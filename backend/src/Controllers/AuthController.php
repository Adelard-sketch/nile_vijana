<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Accounts;
use App\Services\EmailTemplates;
use App\Services\Mailer;
use App\Services\SignInCodes;
use App\Support\Present;

/**
 * Sign-up and sign-in.
 *
 *  Sign up   POST /api/auth/register        name, username, email, phone, password, role
 *            -> welcome email + a 6-digit code to the email -> POST /api/auth/login/verify
 *  Sign in   POST /api/auth/login           login (email OR username) + password
 *            -> a 6-digit code to the email  -> POST /api/auth/login/verify {ticket, code}
 *            POST /api/auth/login/resend {ticket} sends a new code (60 s apart, 5 at most)
 *  Forgot    POST /api/auth/password/forgot {login} -> code by email
 *            POST /api/auth/password/reset  {ticket, code, password}
 *
 * Phone numbers are part of the account (payments, alerts) but are not sign-in identifiers.
 * Only /login/verify and /password/reset return {"token": "...", "user": {...}}.
 */
final class AuthController extends Controller
{
    private const LABELS = [
        'name' => 'Full name', 'username' => 'Username', 'email' => 'Email address',
        'phone' => 'Phone number', 'password' => 'Password', 'role' => 'Account type',
        'login' => 'Email or username', 'code' => 'Code', 'ticket' => 'Sign-in session',
    ];

    public function register(Request $req): Response
    {
        $input = $req->all();
        if (isset($input['username'])) {
            $input['username'] = Accounts::normalizeUsername((string) $input['username']);
        }
        $d = Validator::validate($input, [
            'name'                  => 'required|string|min:2|max:120',
            'username'              => 'required|string|max:30',
            'email'                 => 'required|email|max:190',
            'phone'                 => 'required|phone',
            'password'              => 'required|string|max:100',
            'password_confirmation' => 'nullable|string|max:100',
            'role'                  => 'nullable|in:fan,creator,studio',
        ], self::LABELS);

        $errors = [];
        if ($problem = Accounts::usernameProblem($d['username'])) {
            $errors['username'] = $problem;
        } elseif (DB::value('SELECT id FROM {users} WHERE username = ?', [$d['username']])) {
            $errors['username'] = 'That username is taken. Try another one.';
        }
        if (DB::value('SELECT id FROM {users} WHERE email = ?', [$d['email']])) {
            $errors['email'] = 'An account with this email already exists. Sign in instead, or use Forgot password.';
        }
        if (DB::value('SELECT id FROM {users} WHERE phone = ?', [$d['phone']])) {
            $errors['phone'] = 'This phone number is already on another account.';
        }
        if (isset($d['password_confirmation']) && $d['password_confirmation'] !== $d['password']) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }
        try {
            $strength = Accounts::checkPassword($d['password']);
        } catch (HttpException $e) {
            $errors += $e->errors;
            $strength = 'weak';
        }
        if ($errors) {
            throw HttpException::invalid('Your account could not be created. Please fix the highlighted fields.', $errors);
        }

        $role = $d['role'] ?? 'fan';
        $userId = DB::tx(static function () use ($d, $role) {
            $id = DB::insert('users', [
                'name'          => $d['name'],
                'username'      => $d['username'],
                'email'         => $d['email'],
                'phone'         => $d['phone'],
                'country'       => (string) config('app.country', 'GH'),
                'password_hash' => Auth::hashPassword($d['password']),
            ]);
            Auth::addRole($id, 'fan');            // everyone can listen and buy
            if ($role === 'creator') {
                Auth::addRole($id, 'artist');
                Auth::addRole($id, 'producer');
            } elseif ($role === 'studio') {
                Auth::addRole($id, 'studio_owner');
            }
            return $id;
        });

        $user = DB::one('SELECT * FROM {users} WHERE id = ?', [$userId]);
        $welcome = EmailTemplates::welcome($user['name'], $user['username'], Auth::roles($userId));
        Mailer::send($user['email'], $welcome['subject'], $welcome['html']);
        $this->attempt($user['email'], $req->ip(), true);

        // Confirm the email address with the same one-time code used at every sign-in.
        $challenge = SignInCodes::start($user, 'login', $req->ip());
        return Response::created($challenge + [
            'registered'        => true,
            'password_strength' => $strength,
            'message'           => 'Your account is ready. Enter the code we emailed you to finish.',
        ]);
    }

    /** Step 1: email or username + password. A correct password sends a code by email. */
    public function login(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'login'    => 'required|string|max:190',
            'password' => 'required|string|max:100',
        ], self::LABELS);
        $identifier = strtolower(trim($d['login']));
        if (preg_match('/^\+?[\d\s\-()]{7,}$/', $identifier)) {
            throw HttpException::invalid('Sign in with your email address or username.', [
                'login' => 'Phone numbers cannot be used to sign in. Use your email address or username.',
            ]);
        }
        $this->throttle($identifier, $req->ip());

        $user = str_contains($identifier, '@')
            ? DB::one('SELECT * FROM {users} WHERE email = ?', [$identifier])
            : DB::one('SELECT * FROM {users} WHERE username = ?', [Accounts::normalizeUsername($identifier)]);

        // Same work whether or not the account exists, so response times reveal nothing.
        if ($user && $user['password_hash']) {
            $ok = password_verify($d['password'], $user['password_hash']);
        } else {
            password_hash($d['password'], PASSWORD_BCRYPT);
            $ok = false;
        }
        $this->attempt($identifier, $req->ip(), $ok);
        if (!$ok || !$user) {
            throw new HttpException(401, 'The email, username or password is not correct.', [
                'password' => 'Check your email or username and password, then try again.',
            ]);
        }
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden('This account is suspended. Contact the Nile Vijana team.');
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::update('users', ['password_hash' => Auth::hashPassword($d['password'])], 'id = ?', [$user['id']]);
        }
        return SignInCodes::start($user, 'login', $req->ip()) + [
            'password_strength' => password_strength($d['password']),
            'message'           => 'We sent a 6-digit code to your email. Enter it to finish signing in.',
        ];
    }

    /** Step 2: the code from the email. Returns the session token. */
    public function verifyLogin(Request $req): array
    {
        $d = Validator::validate($req->all(), ['ticket' => 'required|string|max:64', 'code' => 'required|string|max:12'], self::LABELS);
        $user = SignInCodes::verify($d['ticket'], $d['code'], 'login');
        return $this->session((int) $user['id']);
    }

    public function resendLogin(Request $req): array
    {
        $d = Validator::validate($req->all(), ['ticket' => 'required|string|max:64'], self::LABELS);
        return SignInCodes::resend($d['ticket'], 'login') + ['message' => 'We sent a new code. The previous code no longer works.'];
    }

    /** Forgot password: always answers the same way, so it never reveals who has an account. */
    public function forgotPassword(Request $req): array
    {
        $d = Validator::validate($req->all(), ['login' => 'required|string|max:190'], self::LABELS);
        $identifier = strtolower(trim($d['login']));
        $this->throttle($identifier, $req->ip());
        $user = str_contains($identifier, '@')
            ? DB::one('SELECT * FROM {users} WHERE email = ?', [$identifier])
            : DB::one('SELECT * FROM {users} WHERE username = ?', [Accounts::normalizeUsername($identifier)]);
        $this->attempt('reset:' . $identifier, $req->ip(), false);

        $generic = [
            'otp_required' => true,
            'ticket'       => bin2hex(random_bytes(24)),
            'email'        => null,
            'expires_in'   => (int) config('auth.otp_minutes', 10) * 60,
            'resend_in'    => (int) config('auth.otp_resend_seconds', 60),
            'message'      => 'If an account matches, we emailed it a 6-digit code to reset the password.',
        ];
        if (!$user || $user['status'] !== 'active' || empty($user['email'])) {
            return $generic;
        }
        $recent = (int) DB::value("SELECT COUNT(*) FROM {auth_challenges} WHERE user_id = ? AND purpose = 'reset' AND created_at > ?", [$user['id'], now(-3600)]);
        if ($recent >= 3) {
            return $generic;
        }
        return array_merge($generic, SignInCodes::start($user, 'reset', $req->ip()), ['email' => null]);
    }

    public function resendReset(Request $req): array
    {
        $d = Validator::validate($req->all(), ['ticket' => 'required|string|max:64'], self::LABELS);
        try {
            return SignInCodes::resend($d['ticket'], 'reset') + ['email' => null, 'message' => 'If an account matches, we sent a new code.'];
        } catch (HttpException $e) {
            if ($e->status === 429) {
                throw $e;
            }
            return ['otp_required' => true, 'ticket' => $d['ticket'], 'resend_in' => 60, 'message' => 'If an account matches, we sent a new code.'];
        }
    }

    public function resetPassword(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'ticket'   => 'required|string|max:64',
            'code'     => 'required|string|max:12',
            'password' => 'required|string|max:100',
        ], self::LABELS + ['password' => 'New password']);
        Accounts::checkPassword($d['password']);
        $user = SignInCodes::verify($d['ticket'], $d['code'], 'reset');
        DB::update('users', ['password_hash' => Auth::hashPassword($d['password'])], 'id = ?', [$user['id']]);
        DB::run('DELETE FROM {auth_tokens} WHERE user_id = ?', [$user['id']]);      // sign out everywhere else
        return $this->session((int) $user['id']) + ['password_reset' => true];
    }

    /** GET /api/auth/username?username=kofi.beats -> {available, problem} (sign-up form hint). */
    public function usernameAvailable(Request $req): array
    {
        $username = Accounts::normalizeUsername((string) ($req->query['username'] ?? ''));
        $problem = Accounts::usernameProblem($username);
        if (!$problem && DB::value('SELECT id FROM {users} WHERE username = ?', [$username])) {
            $problem = 'That username is taken. Try another one.';
        }
        return ['username' => $username, 'available' => $problem === null, 'problem' => $problem];
    }

    /** The old phone-code sign-in. Kept so old app versions get a clear answer instead of a 404. */
    public function phoneSignInRemoved(Request $req): array
    {
        throw new HttpException(410, 'Signing in with a phone code is no longer available. Sign in with your email address or username and your password.');
    }

    public function logout(Request $req): Response
    {
        Auth::revoke((string) $req->bearerToken());
        return Response::noContent();
    }

    private function session(int $userId): array
    {
        $user = DB::one('SELECT * FROM {users} WHERE id = ?', [$userId]);
        return [
            'token' => Auth::issueToken($userId),
            'user'  => Present::user($user, Auth::roles($userId)),
        ];
    }

    /** Too many wrong passwords for one account or from one network: wait 15 minutes. */
    private function throttle(string $identifier, string $ip): void
    {
        $since = now(-900);
        $byId = (int) DB::value('SELECT COUNT(*) FROM {login_attempts} WHERE identifier = ? AND success = 0 AND created_at > ?', [$identifier, $since]);
        $byIp = (int) DB::value('SELECT COUNT(*) FROM {login_attempts} WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
        if ($byId >= (int) config('auth.login_max_failures', 5) || $byIp >= (int) config('auth.login_ip_failures', 20)) {
            throw new HttpException(429, 'Too many unsuccessful attempts. Wait 15 minutes, or reset your password.');
        }
    }

    private function attempt(string $identifier, string $ip, bool $success): void
    {
        DB::insert('login_attempts', ['identifier' => mb_substr($identifier, 0, 190), 'ip' => $ip, 'success' => $success ? 1 : 0]);
    }
}
