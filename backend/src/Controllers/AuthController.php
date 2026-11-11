<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Sms;
use App\Support\Present;

/**
 * Two ways to sign in:
 *  1. Phone + one-time SMS code (most users)   POST /api/auth/otp, then POST /api/auth/verify
 *  2. Email or phone + password                POST /api/auth/register, POST /api/auth/login
 * Both return {"token": "...", "user": {...}}. Send the token as: Authorization: Bearer <token>
 */
final class AuthController extends Controller
{
    public function register(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'nullable|email|max:190',
            'phone'    => 'nullable|phone',
            'password' => 'required|string|min:8|max:100',
        ]);
        if (empty($d['email']) && empty($d['phone'])) {
            throw HttpException::invalid('Give an email or a phone number', ['email' => 'Email or phone required']);
        }
        if (!empty($d['email']) && DB::value('SELECT id FROM users WHERE email = ?', [$d['email']])) {
            throw HttpException::conflict('An account with this email already exists');
        }
        if (!empty($d['phone']) && DB::value('SELECT id FROM users WHERE phone = ?', [$d['phone']])) {
            throw HttpException::conflict('An account with this phone number already exists');
        }

        $userId = DB::tx(static function () use ($d) {
            $id = DB::insert('users', [
                'name'          => $d['name'],
                'email'         => $d['email'] ?? null,
                'phone'         => $d['phone'] ?? null,
                'password_hash' => Auth::hashPassword($d['password']),
            ]);
            Auth::addRole($id, 'fan');
            return $id;
        });
        return Response::created($this->session($userId));
    }

    public function login(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'login'    => 'required|string|max:190',   // email or phone
            'password' => 'required|string|max:100',
        ]);
        $user = str_contains($d['login'], '@')
            ? DB::one('SELECT * FROM users WHERE email = ?', [strtolower($d['login'])])
            : DB::one('SELECT * FROM users WHERE phone = ?', [normalize_phone($d['login']) ?? '']);

        if (!$user || !$user['password_hash'] || !password_verify($d['password'], $user['password_hash'])) {
            throw new HttpException(401, 'Wrong email/phone or password');
        }
        if ($user['status'] !== 'active') {
            throw HttpException::forbidden('This account is suspended');
        }
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::update('users', ['password_hash' => Auth::hashPassword($d['password'])], 'id = ?', [$user['id']]);
        }
        return $this->session((int) $user['id']);
    }

    /** Send a 6-digit sign-in code by SMS. */
    public function requestOtp(Request $req): array
    {
        $d = Validator::validate($req->all(), ['phone' => 'required|phone']);
        $phone = $d['phone'];

        $recentForPhone = (int) DB::value('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > ?', [$phone, now(-600)]);
        $recentForIp = (int) DB::value('SELECT COUNT(*) FROM otp_codes WHERE ip = ? AND created_at > ?', [$req->ip(), now(-3600)]);
        if ($recentForPhone >= 3 || $recentForIp >= 20) {
            throw new HttpException(429, 'Too many codes requested. Wait a few minutes and try again.');
        }

        $code = (string) random_int(100000, 999999);
        DB::insert('otp_codes', [
            'phone'      => $phone,
            'code_hash'  => $this->codeHash($phone, $code),
            'ip'         => $req->ip(),
            'expires_at' => now(600),
        ]);
        Sms::send($phone, "Your Nile Vijana code is $code. It expires in 10 minutes. Don't share it.");

        $out = ['sent' => true, 'phone' => $phone, 'expires_in' => 600];
        // Testing without a phone: in development with the 'log' SMS driver the code is returned.
        if (config('app.env') === 'development' && config('sms.driver') === 'log') {
            $out['dev_code'] = $code;
        }
        return $out;
    }

    public function verifyOtp(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'phone' => 'required|phone',
            'code'  => 'required|string|max:10',
            'name'  => 'nullable|string|min:2|max:120',   // used when this phone is new
        ]);
        $otp = DB::one(
            'SELECT * FROM otp_codes WHERE phone = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
            [$d['phone']]
        );
        if (!$otp) {
            throw HttpException::invalid('This code has expired. Request a new one.', ['code' => 'Expired']);
        }
        if ($otp['attempts'] >= 5) {
            throw new HttpException(429, 'Too many wrong attempts. Request a new code.');
        }
        if (!hash_equals($otp['code_hash'], $this->codeHash($d['phone'], $d['code']))) {
            DB::run('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [$otp['id']]);
            throw HttpException::invalid('Wrong code', ['code' => 'Wrong code']);
        }
        DB::update('otp_codes', ['used_at' => now()], 'id = ?', [$otp['id']]);

        $user = DB::one('SELECT id, status FROM users WHERE phone = ?', [$d['phone']]);
        $isNew = false;
        if (!$user) {
            $isNew = true;
            $userId = DB::tx(static function () use ($d) {
                $id = DB::insert('users', [
                    'name'  => $d['name'] ?? ('Fan ' . substr($d['phone'], -4)),
                    'phone' => $d['phone'],
                ]);
                Auth::addRole($id, 'fan');
                return $id;
            });
        } else {
            if ($user['status'] !== 'active') {
                throw HttpException::forbidden('This account is suspended');
            }
            $userId = (int) $user['id'];
        }
        return $this->session($userId) + ['is_new' => $isNew];
    }

    public function logout(Request $req): Response
    {
        Auth::revoke((string) $req->bearerToken());
        return Response::noContent();
    }

    private function session(int $userId): array
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        return [
            'token' => Auth::issueToken($userId),
            'user'  => Present::user($user, Auth::roles($userId)),
        ];
    }

    private function codeHash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone . '|' . $code, (string) config('app.key'));
    }
}
