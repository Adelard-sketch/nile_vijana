<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Simple input validation.
 *
 *   $data = Validator::validate($req->all(), [
 *       'title' => 'required|string|max:160',
 *       'bpm'   => 'nullable|int|min:40|max:250',
 *       'tier'  => 'required|in:mp3_lease,wav_lease,unlimited,exclusive',
 *       'phone' => 'required|phone',
 *   ]);
 *
 * Returns only the fields listed, cleaned and type-cast.
 * Throws a 422 error listing every problem when something is wrong, with messages a
 * non-technical person understands: "Title is required.", "Price must be at most 500."
 * Pass $labels to name fields the way the form does: ['stage_name' => 'Artist name'].
 *
 * Rules: required, nullable, string, int, bool, email, phone, date (Y-m-d),
 *        datetime (Y-m-d H:i), time (H:i), array, min:N, max:N, in:a,b,c
 *        (min/max = length for strings, value for numbers, count for arrays)
 */
final class Validator
{
    /** Names used in messages when a controller passes no label. */
    private const LABELS = [
        'title' => 'Title', 'genre' => 'Genre', 'mood' => 'Mood', 'language' => 'Language', 'price' => 'Price',
        'bpm' => 'BPM', 'key' => 'Musical key', 'tags' => 'Tags', 'tiers' => 'Licence prices', 'description' => 'Description',
        'release_date' => 'Release date', 'album_id' => 'Album', 'stage_name' => 'Stage name', 'display_name' => 'Name',
        'contact_name' => 'Contact name', 'bio' => 'Biography', 'city' => 'City or town', 'address' => 'Address',
        'equipment' => 'Facilities and equipment', 'amenities' => 'Amenities', 'hourly_rate' => 'Hourly rate',
        'min_hours' => 'Minimum hours', 'room_id' => 'Room', 'start_at' => 'Start time', 'hours' => 'Number of hours',
        'notes' => 'Notes', 'phone' => 'Phone number', 'email' => 'Email address', 'name' => 'Name', 'amount' => 'Amount',
        'payout_phone' => 'Mobile money number', 'provider' => 'Mobile money network', 'website' => 'Website',
        'members_count' => 'Number of members', 'experience_years' => 'Years of experience', 'specialization' => 'Specialisation',
        'company' => 'Company', 'genres' => 'Genres', 'rooms' => 'Rooms', 'type' => 'Type', 'reason' => 'Reason',
        'note' => 'Note', 'subject' => 'Subject', 'body' => 'Question', 'category' => 'Topic', 'answer' => 'Answer',
        'current_password' => 'Current password', 'new_password' => 'New password', 'password' => 'Password',
        'username' => 'Username', 'duration_minutes' => 'Session length', 'headline' => 'Headline', 'items' => 'Items',
    ];

    public static function label(string $field, array $labels = []): string
    {
        return $labels[$field] ?? self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Like validate() but never throws: returns [$clean, $errors], so a controller can add
     * its own checks (files, prices) and report every problem in one response.
     */
    public static function check(array $input, array $rules, array $labels = []): array
    {
        try {
            return [self::validate($input, $rules, $labels), []];
        } catch (HttpException $e) {
            $clean = [];
            foreach (array_keys($rules) as $field) {
                if (!isset($e->errors[$field])) {
                    try {
                        $clean += self::validate([$field => $input[$field] ?? null], [$field => $rules[$field]], $labels);
                    } catch (HttpException) {
                    }
                }
            }
            return [$clean, $e->errors];
        }
    }

    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            $label = self::label($field, $labels);

            if ($empty) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = "$label is required.";
                } elseif (array_key_exists($field, $input) && in_array('nullable', $rulesList, true)) {
                    $clean[$field] = null;     // explicitly cleared
                }
                continue;
            }

            $type = 'string';
            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = null;

                switch ($name) {
                    case 'required':
                    case 'nullable':
                        break;
                    case 'string':
                        if (!is_scalar($value)) {
                            $error = "$label must be text.";
                        } else {
                            $value = (string) $value;
                        }
                        break;
                    case 'int':
                        $type = 'number';
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $error = "$label must be a whole number.";
                        } else {
                            $value = (int) $value;
                        }
                        break;
                    case 'bool':
                        $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($b === null) {
                            $error = "$label must be yes or no.";
                        } else {
                            $value = $b;
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $error = "$label must be a valid email address, like name@example.com.";
                        } else {
                            $value = strtolower((string) $value);
                        }
                        break;
                    case 'phone':
                        $p = normalize_phone((string) $value);
                        if ($p === null) {
                            $error = "$label must be a valid phone number, like 024 123 4567.";
                        } else {
                            $value = $p;
                        }
                        break;
                    case 'date':
                        $d = \DateTime::createFromFormat('!Y-m-d', (string) $value);
                        if (!$d || $d->format('Y-m-d') !== $value) {
                            $error = "$label must be a valid date.";
                        }
                        break;
                    case 'datetime':
                        // Accepts "2026-11-05 14:00" and HTML datetime-local "2026-11-05T14:00".
                        $d = \DateTime::createFromFormat('!Y-m-d H:i', substr(str_replace('T', ' ', (string) $value), 0, 16));
                        if (!$d) {
                            $error = "$label must be a valid date and time.";
                        } else {
                            $value = $d->format('Y-m-d H:i:00');
                        }
                        break;
                    case 'time':
                        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr((string) $value, 0, 5))) {
                            $error = "$label must be a time like 09:00.";
                        } else {
                            $value = substr((string) $value, 0, 5);
                        }
                        break;
                    case 'array':
                        $type = 'array';
                        if (is_string($value)) {             // allow JSON strings in multipart forms
                            $decoded = json_decode($value, true);
                            $value = is_array($decoded) ? $decoded : $value;
                        }
                        if (!is_array($value)) {
                            $error = "$label must be a list.";
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $error = "Choose a valid option for " . lcfirst($label) . '.';
                        }
                        break;
                    case 'min':
                    case 'max':
                        $n = match ($type) {
                            'number' => $value,
                            'array'  => is_array($value) ? count($value) : 0,
                            default  => mb_strlen((string) $value),
                        };
                        $limit = (int) $arg;
                        if ($name === 'min' && $n < $limit) {
                            $error = match ($type) {
                                'string' => "$label must be at least $limit characters.",
                                'array'  => "$label needs at least $limit " . ($limit === 1 ? 'item' : 'items') . '.',
                                default  => "$label must be at least " . number_format($limit) . '.',
                            };
                        }
                        if ($name === 'max' && $n > $limit) {
                            $error = match ($type) {
                                'string' => "$label must be at most $limit characters.",
                                'array'  => "$label can have at most $limit items.",
                                default  => "$label must be at most " . number_format($limit) . '.',
                            };
                        }
                        break;
                    default:
                        throw new \LogicException("Unknown validation rule: $name");
                }

                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = $value;
            }
        }

        if ($errors) {
            throw HttpException::invalid('Please check the highlighted fields.', $errors);
        }
        return $clean;
    }
}
