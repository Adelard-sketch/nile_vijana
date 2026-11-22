<?php
declare(strict_types=1);

namespace App\Services;

use App\Controllers\StudioController;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Validator;
use App\Models\CreatorModel;
use App\Services\Accounts;
use App\Services\EmailTemplates;
use App\Services\Mailer;

/**
 * One onboarding flow for every account type an admin can create:
 * artist, choir, producer, manager, vocal coach and studio.
 *
 * The admin fills one form; the fields that apply depend on the type.
 * The account is created (or an existing user with the same phone/email is reused),
 * the right role is granted, and the profile is stored verified, so the admin can
 * upload songs or beats for it straight away. Studios are created verified with
 * their rooms, prices and default opening hours.
 *
 * The person signs in with their email or username, the password (set here by the
 * admin, or chosen by the person with "Forgot password"), and a code sent to the email.
 * An email address is therefore required.
 */
final class Onboarding
{
    public const TYPES = ['artist', 'choir', 'producer', 'manager', 'vocal_coach', 'studio'];

    /** The new account made by the last create() call, for the welcome email. */
    private static ?array $created = null;

    /** Names of the fields as the onboarding form shows them (used in error messages). */
    public static function labels(string $type): array
    {
        return [
            'display_name' => match ($type) {
                'studio' => 'Studio name', 'choir' => 'Choir name', 'producer' => 'Producer name',
                'manager' => 'Full name', 'vocal_coach' => 'Coach name', default => 'Artist name',
            },
            'genres'         => $type === 'producer' ? 'Music genres' : 'Genres',
            'city'           => $type === 'studio' ? 'City' : 'Location',
            'equipment'      => 'Facilities and equipment',
            'rooms'          => 'Rooms and prices',
            'members_count'  => 'Number of members',
            'specialization' => 'Specialisation',
            'email'          => 'Email address',
        ];
    }

    /** Role granted for each type. */
    private const ROLE = [
        'artist'      => 'artist',
        'choir'       => 'choir',
        'producer'    => 'producer',
        'manager'     => 'manager',
        'vocal_coach' => 'vocal_coach',
        'studio'      => 'studio_owner',
    ];

    /** Validation rules: common fields + the ones each type needs. */
    public static function rules(string $type): array
    {
        $rules = [
            'type'          => 'required|in:' . implode(',', self::TYPES),
            'display_name'  => 'required|string|min:2|max:' . ($type === 'studio' ? 120 : 80),
            'contact_name'  => 'nullable|string|min:2|max:120',
            'phone'         => 'nullable|phone',
            'email'         => 'required|email|max:190',
            'username'      => 'nullable|string|max:30',
            'password'      => 'nullable|string|max:100',
            'bio'           => 'nullable|string|max:3000',
            'city'          => 'nullable|string|max:80',
            'website'       => 'nullable|string|max:255',
        ];
        return array_merge($rules, match ($type) {
            'artist'      => ['genres' => 'required|string|max:255'],
            'producer'    => ['genres' => 'required|string|max:255'],
            'choir'       => ['city' => 'required|string|max:80', 'members_count' => 'required|int|min:2|max:5000', 'genres' => 'nullable|string|max:255'],
            'manager'     => ['company' => 'nullable|string|max:120', 'managed_artists' => 'nullable|array|max:50'],
            'vocal_coach' => ['experience_years' => 'required|int|min:0|max:80', 'specialization' => 'required|string|min:2|max:255'],
            'studio'      => [
                'city'         => 'required|string|max:80',
                'address'      => 'required|string|max:255',
                'equipment'    => 'required|string|max:3000',   // facilities
                'amenities'    => 'nullable|string|max:1000',
                'backup_power' => 'nullable|bool',
                'rooms'        => 'required|array|min:1|max:10',
            ],
            default       => [],
        });
    }

    /**
     * @param array      $input   request fields
     * @param array|null $picture uploaded image (profile picture, or studio cover)
     * @return array ['user_id' => int, 'type' => string, 'studio_id' => ?int]
     */
    public static function create(array $admin, array $input, ?array $picture): array
    {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            throw HttpException::invalid('Choose an account type', ['type' => 'Use ' . implode(', ', self::TYPES)]);
        }
        if (isset($input['username'])) {
            $input['username'] = Accounts::normalizeUsername((string) $input['username']);
        }
        $d = Validator::validate($input, self::rules($type), self::labels($type));
        if (!empty($d['password'])) {
            Accounts::checkPassword($d['password']);
        }
        if (!empty($d['username'])) {
            $problem = Accounts::usernameProblem($d['username']);
            $owner = DB::value('SELECT id FROM users WHERE username = ?', [$d['username']]);
            $sameAccount = $owner && $owner == DB::value('SELECT id FROM users WHERE email = ?', [strtolower((string) $d['email'])]);
            if ($problem || ($owner && !$sameAccount)) {
                throw HttpException::invalid('Choose another username.', ['username' => $problem ?? 'That username is taken. Try another one.']);
            }
        }
        $rooms = $type === 'studio' ? self::rooms($d['rooms']) : [];
        $managed = $type === 'manager' ? self::managedArtists($d['managed_artists'] ?? []) : [];

        // Store the image first so a bad file fails before anything is written.
        $image = $picture ? Storage::storeImage($picture, $type === 'studio' ? 'studios' : 'avatars', 'picture') : null;

        try {
            self::$created = null;
            $result = self::write($admin, $type, $d, $rooms, $managed, $image);
            if (self::$created) {
                $c = self::$created;
                $mail = $c['has_password']
                    ? EmailTemplates::welcome($c['name'], $c['username'], [self::ROLE[$type]])
                    : EmailTemplates::accountCreatedByAdmin($c['name'], $c['email'], $c['username'], str_replace('_', ' ', $type));
                Mailer::send($c['email'], $mail['subject'], $mail['html']);
                $result['username'] = $c['username'];
            }
            return $result;
        } catch (\Throwable $e) {
            if ($image) {
                @unlink(BASE_PATH . '/public/uploads/' . $image);   // nothing was saved: drop the uploaded image
            }
            throw $e;
        }
    }

    private static function write(array $admin, string $type, array $d, array $rooms, array $managed, ?string $image): array
    {
        return DB::tx(static function () use ($admin, $type, $d, $rooms, $managed, $image): array {
            $uid = self::account($type, $d);
            Auth::addRole($uid, self::ROLE[$type]);

            if ($type === 'studio') {
                $studioId = self::studio($admin, $uid, $d, $rooms, $image);
                return ['user_id' => $uid, 'type' => $type, 'studio_id' => $studioId];
            }

            $creators = new CreatorModel();
            DB::insert('creator_profiles', [
                'user_id'          => $uid,
                'stage_name'       => $d['display_name'],
                'slug'             => $creators->uniqueSlug($d['display_name'], $uid),
                'type'             => $type,
                'bio'              => $d['bio'] ?? null,
                'city'             => $d['city'] ?? null,
                'avatar_path'      => $image,
                'verified'         => 1,
                'genres'           => isset($d['genres']) ? self::cleanList($d['genres']) : null,
                'contact_email'    => $d['email'] ?? null,
                'contact_phone'    => $d['phone'] ?? null,
                'website'          => $d['website'] ?? null,
                'members_count'    => $d['members_count'] ?? null,
                'experience_years' => $d['experience_years'] ?? null,
                'specialization'   => $d['specialization'] ?? null,
                'company'          => $d['company'] ?? null,
                'onboarded_by'     => (int) $admin['id'],
            ]);

            if ($type === 'manager') {
                foreach ($managed as $artistId) {
                    DB::run('INSERT IGNORE INTO manager_artists (manager_id, artist_id) VALUES (?, ?)', [$uid, $artistId]);
                }
            }
            if ($type === 'vocal_coach') {
                // Listed on the public mentors page so artists can book coaching sessions.
                DB::run(
                    'INSERT INTO mentor_profiles (user_id, headline, specialties, active) VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE headline = VALUES(headline), specialties = VALUES(specialties), active = 1',
                    [$uid, mb_substr('Vocal coach - ' . $d['specialization'], 0, 160), $d['specialization']]
                );
            }
            return ['user_id' => $uid, 'type' => $type, 'studio_id' => null];
        });
    }

    /** Find the user by phone/email or create a new account. Returns the user id. */
    private static function account(string $type, array $d): int
    {
        $phone = $d['phone'] ?? null;
        $email = isset($d['email']) ? strtolower($d['email']) : null;
        $byPhone = $phone ? DB::one('SELECT id, name FROM users WHERE phone = ?', [$phone]) : null;
        $byEmail = $email ? DB::one('SELECT id, name FROM users WHERE email = ?', [$email]) : null;
        if ($byPhone && $byEmail && (int) $byPhone['id'] !== (int) $byEmail['id']) {
            throw HttpException::conflict('That phone number and email belong to two different accounts.');
        }
        $user = $byPhone ?? $byEmail;

        if ($user) {
            $uid = (int) $user['id'];
            if ($type !== 'studio' && DB::value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$uid])) {
                throw HttpException::conflict("{$user['name']} already has a profile. Edit it instead of onboarding again.");
            }
            return $uid;
        }

        $name = $d['contact_name'] ?? $d['display_name'];
        $username = !empty($d['username']) ? $d['username'] : Accounts::uniqueUsername((string) $email);
        $uid = DB::insert('users', [
            'name'          => $name,
            'username'      => $username,
            'phone'         => $phone,
            'email'         => $email,
            'country'       => (string) config('app.country', 'GH'),
            'password_hash' => !empty($d['password']) ? Auth::hashPassword($d['password']) : null,
        ]);
        Auth::addRole($uid, 'fan');
        // Tell the person how to sign in (sent after the transaction; failures are only logged).
        self::$created = ['name' => $name, 'email' => (string) $email, 'username' => $username, 'type' => $type, 'has_password' => !empty($d['password'])];
        return $uid;
    }

    private static function studio(array $admin, int $ownerId, array $d, array $rooms, ?string $cover): int
    {
        $studioId = DB::insert('studios', [
            'owner_id'     => $ownerId,
            'name'         => $d['display_name'],
            'slug'         => StudioController::uniqueSlug($d['display_name']),
            'description'  => $d['bio'] ?? null,
            'city'         => $d['city'],
            'address'      => $d['address'],
            'phone'        => $d['phone'] ?? null,
            'equipment'    => $d['equipment'],
            'amenities'    => $d['amenities'] ?? null,
            'backup_power' => !empty($d['backup_power']) ? 1 : 0,
            'cover_path'   => $cover,
            'status'       => 'verified',
            'reviewed_by'  => (int) $admin['id'],
            'reviewed_at'  => now(),
        ]);
        foreach ($rooms as $room) {
            $roomId = DB::insert('studio_rooms', $room + ['studio_id' => $studioId]);
            foreach (StudioController::DEFAULT_HOURS as $day => [$open, $close]) {
                DB::insert('room_hours', ['room_id' => $roomId, 'weekday' => $day, 'open_time' => $open, 'close_time' => $close]);
            }
        }
        return $studioId;
    }

    /** Validate the studio's rooms (name + hourly price). */
    private static function rooms(array $rooms): array
    {
        $clean = [];
        foreach (array_values($rooms) as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $rate = (int) ($r['hourly_rate'] ?? 0);
            $min = (int) ($r['min_hours'] ?? 1);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                throw HttpException::invalid('Give each room a name.', ["rooms.$i.name" => 'Room ' . ($i + 1) . ': room name is required.']);
            }
            $low = (int) config('money.min_room_rate', 20);
            $high = (int) config('money.max_room_rate', 10000);
            if ($rate < $low || $rate > $high) {
                throw HttpException::invalid('Check the room prices.', ["rooms.$i.hourly_rate" => 'Room ' . ($i + 1) . ": hourly price must be between GH₵ $low and GH₵ " . number_format($high) . '.']);
            }
            if ($min < 1 || $min > 12) {
                throw HttpException::invalid('Check the minimum booking.', ["rooms.$i.min_hours" => 'Room ' . ($i + 1) . ': minimum booking must be 1 to 12 hours.']);
            }
            $clean[] = [
                'name'              => $name,
                'description'       => isset($r['description']) ? mb_substr((string) $r['description'], 0, 2000) : null,
                'hourly_rate'       => $rate,
                'min_hours'         => $min,
                'engineer_included' => filter_var($r['engineer_included'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            ];
        }
        return $clean;
    }

    /** Manager's artists must be existing artist or choir profiles. */
    private static function managedArtists(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $found = array_map('intval', array_column(DB::all(
            "SELECT user_id FROM creator_profiles WHERE user_id IN ($in) AND type IN ('artist', 'both', 'choir')",
            $ids
        ), 'user_id'));
        $missing = array_diff($ids, $found);
        if ($missing) {
            throw HttpException::invalid('Some selected artists were not found', ['managed_artists' => 'Unknown artist id(s): ' . implode(', ', $missing)]);
        }
        return $ids;
    }

    /** "afro, Gospel ,,amapiano" -> "afro, Gospel, amapiano" */
    public static function cleanList(string $list): ?string
    {
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $list)), 'strlen')));
        return $items ? mb_substr(implode(', ', $items), 0, 255) : null;
    }
}
