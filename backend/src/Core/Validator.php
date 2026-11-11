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
 * Throws a 422 error listing every problem when something is wrong.
 *
 * Rules: required, nullable, string, int, bool, email, phone, date (Y-m-d),
 *        datetime (Y-m-d H:i), time (H:i), array, min:N, max:N, in:a,b,c
 *        (min/max = length for strings, value for numbers, count for arrays)
 */
final class Validator
{
    public static function validate(array $input, array $rules): array
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

            if ($empty) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = 'This field is required';
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
                            $error = 'Must be text';
                        } else {
                            $value = (string) $value;
                        }
                        break;
                    case 'int':
                        $type = 'number';
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $error = 'Must be a whole number';
                        } else {
                            $value = (int) $value;
                        }
                        break;
                    case 'bool':
                        $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($b === null) {
                            $error = 'Must be true or false';
                        } else {
                            $value = $b;
                        }
                        break;
                    case 'email':
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $error = 'Must be a valid email address';
                        } else {
                            $value = strtolower((string) $value);
                        }
                        break;
                    case 'phone':
                        $p = normalize_phone((string) $value);
                        if ($p === null) {
                            $error = 'Must be a valid phone number, e.g. 0781234567 or 250781234567';
                        } else {
                            $value = $p;
                        }
                        break;
                    case 'date':
                        $d = \DateTime::createFromFormat('!Y-m-d', (string) $value);
                        if (!$d || $d->format('Y-m-d') !== $value) {
                            $error = 'Must be a date like 2026-11-05';
                        }
                        break;
                    case 'datetime':
                        // Accepts "2026-11-05 14:00" and HTML datetime-local "2026-11-05T14:00".
                        $d = \DateTime::createFromFormat('!Y-m-d H:i', substr(str_replace('T', ' ', (string) $value), 0, 16));
                        if (!$d) {
                            $error = 'Must be a date and time like 2026-11-05 14:00';
                        } else {
                            $value = $d->format('Y-m-d H:i:00');
                        }
                        break;
                    case 'time':
                        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr((string) $value, 0, 5))) {
                            $error = 'Must be a time like 09:00';
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
                            $error = 'Must be a list';
                        }
                        break;
                    case 'in':
                        if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                            $error = 'Must be one of: ' . str_replace(',', ', ', (string) $arg);
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
                            $error = $type === 'string' ? "Must be at least $limit characters" : "Must be at least $limit";
                        }
                        if ($name === 'max' && $n > $limit) {
                            $error = $type === 'string' ? "Must be at most $limit characters" : "Must be at most $limit";
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
            throw HttpException::invalid('Some fields need fixing', $errors);
        }
        return $clean;
    }
}
