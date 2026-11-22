<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Branded HTML emails. Table layout and inline styles, because most email apps
 * (Gmail, Outlook, phone mail) ignore stylesheets. Colours follow the brand:
 * Midnight #141D33, Berry #7A0B47, Stage Amber #F0A232, Paper #F7F3EE,
 * with the same kente-style fabric strip used on the website.
 */
final class EmailTemplates
{
    public static function welcome(string $name, string $username, array $roles = []): array
    {
        $first = self::first($name);
        $isCreator = (bool) array_intersect($roles, ['artist', 'producer', 'choir', 'studio_owner', 'manager', 'vocal_coach']);
        $body = '<h1 style="' . self::h1() . '">Welcome to Nile Vijana Creatives, ' . self::e($first) . '!</h1>'
            . '<p style="' . self::p() . '">Congratulations on joining a growing community of artists, producers, studios and music lovers in Ghana and across Africa. We are glad you are here.</p>'
            . '<p style="' . self::p() . '">Here is what you can do on Nile Vijana:</p>'
            . self::list([
                '<strong>Listen and support.</strong> Stream new music, save favourites, tip artists and buy songs in Ghana cedis.',
                '<strong>License beats.</strong> Preview beats from African producers and buy a lease or exclusive licence with a signed PDF.',
                '<strong>Book studios.</strong> Compare recording studios in Accra, Kumasi, Takoradi and beyond, and book a session with a deposit.',
                '<strong>Grow your career.</strong> Upload music or beats, see your streams and earnings, and get paid to mobile money.',
            ])
            . self::button($isCreator ? 'Open my dashboard' : 'Start listening', frontend_url($isCreator ? 'dashboard/index.html' : 'browse-tracks.html'))
            . '<p style="' . self::small() . '">You sign in with your email address or your username <strong>' . self::e($username) . '</strong>, plus your password. Each time you sign in we email you a one-time code to keep your account safe.</p>';
        return [
            'subject' => 'Welcome to Nile Vijana Creatives',
            'html'    => self::layout('Welcome to Nile Vijana Creatives', $body),
        ];
    }

    public static function signInCode(string $name, string $code, int $minutes, string $purpose = 'login'): array
    {
        $reset = $purpose === 'reset';
        $title = $reset ? 'Your password reset code' : 'Your sign-in code';
        $body = '<h1 style="' . self::h1() . '">' . $title . '</h1>'
            . '<p style="' . self::p() . '">Hello ' . self::e(self::first($name)) . ', ' . ($reset
                ? 'use this code to choose a new password for your Nile Vijana account.'
                : 'use this code to finish signing in to Nile Vijana Creatives.') . '</p>'
            . '<p style="margin:24px 0;text-align:center"><span style="display:inline-block;padding:14px 22px;border-radius:12px;background:#141D33;color:#F0A232;font:700 32px/1 Consolas,Menlo,monospace;letter-spacing:8px">'
            . self::e($code) . '</span></p>'
            . '<p style="' . self::p() . '">The code works for <strong>' . $minutes . ' minutes</strong> and only once.</p>'
            . '<p style="' . self::small() . '">If you did not try to ' . ($reset ? 'reset your password' : 'sign in') . ', ignore this email' . ($reset ? '' : ' and change your password, because someone knows it') . '. Nile Vijana will never ask you for this code by phone or chat.</p>';
        return [
            'subject' => ($reset ? 'Password reset code: ' : 'Nile Vijana sign-in code: ') . $code,
            'html'    => self::layout($title, $body),
        ];
    }

    public static function questionReceived(string $name, string $subject, string $reference): array
    {
        $body = '<h1 style="' . self::h1() . '">We received your question</h1>'
            . '<p style="' . self::p() . '">Thank you, ' . self::e(self::first($name)) . '. Your question <strong>“' . self::e($subject) . '”</strong> is with the Nile Vijana team.</p>'
            . '<p style="' . self::p() . '">We usually answer within two working days, and we will email the answer to this address.</p>'
            . '<p style="' . self::small() . '">Reference: ' . self::e($reference) . '</p>';
        return ['subject' => 'We received your question (' . $reference . ')', 'html' => self::layout('We received your question', $body)];
    }

    public static function questionAnswered(string $name, string $subject, string $question, string $answer, string $reference): array
    {
        $body = '<h1 style="' . self::h1() . '">Your question has been answered</h1>'
            . '<p style="' . self::p() . '">Hello ' . self::e(self::first($name)) . ', the Nile Vijana team replied to your question.</p>'
            . '<p style="margin:18px 0 6px;font:600 13px/1.4 Arial,sans-serif;color:#6b6470;text-transform:uppercase;letter-spacing:.06em">Your question</p>'
            . '<div style="padding:14px 16px;border-radius:10px;background:#F7F3EE;color:#3a3440;font:15px/1.6 Arial,sans-serif"><strong>' . self::e($subject) . '</strong><br>' . nl2br(self::e($question)) . '</div>'
            . '<p style="margin:18px 0 6px;font:600 13px/1.4 Arial,sans-serif;color:#7A0B47;text-transform:uppercase;letter-spacing:.06em">Our answer</p>'
            . '<div style="padding:14px 16px;border-radius:10px;border-left:4px solid #F0A232;background:#fffaf0;color:#1c1a22;font:15px/1.6 Arial,sans-serif">' . nl2br(self::e($answer)) . '</div>'
            . self::button('Ask another question', frontend_url('q-and-a.html'))
            . '<p style="' . self::small() . '">Reference: ' . self::e($reference) . '. Reply to this email if you need more help.</p>';
        return ['subject' => 'Answer to your question: ' . $subject, 'html' => self::layout('Your question has been answered', $body)];
    }

    public static function accountCreatedByAdmin(string $name, string $email, string $username, string $type): array
    {
        $body = '<h1 style="' . self::h1() . '">Your Nile Vijana account is ready</h1>'
            . '<p style="' . self::p() . '">Hello ' . self::e(self::first($name)) . ', the Nile Vijana team has set up your ' . self::e($type) . ' account.</p>'
            . self::list([
                'Email: <strong>' . self::e($email) . '</strong>',
                'Username: <strong>' . self::e($username) . '</strong>',
            ])
            . '<p style="' . self::p() . '">To choose your password, open the sign-in page, select <strong>Forgot password?</strong> and enter your email. We will send you a code to set it.</p>'
            . self::button('Set my password', frontend_url('auth/login.html?forgot=1'));
        return ['subject' => 'Your Nile Vijana Creatives account is ready', 'html' => self::layout('Your account is ready', $body)];
    }

    /* ------------------------------------------------------------------ pieces */

    private static function layout(string $title, string $content): string
    {
        $year = date('Y');
        $site = frontend_url('index.html');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#efe9e2">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#efe9e2"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;border-radius:16px;overflow:hidden;background:#ffffff">'
            . '<tr><td style="background:#141D33;padding:22px 28px">'
            . '<a href="' . self::e($site) . '" style="text-decoration:none"><span style="display:block;font:700 15px/1 Arial,sans-serif;color:#ffffff;letter-spacing:.02em">nile vijana</span>'
            . '<span style="display:block;margin-top:4px;font:700 20px/1 Arial,sans-serif;color:#F0A232;letter-spacing:.32em">CREATIVES</span></a></td></tr>'
            . '<tr><td style="padding:0;line-height:0">' . self::fabric() . '</td></tr>'
            . '<tr><td style="padding:28px">' . $content . '</td></tr>'
            . '<tr><td style="padding:0;line-height:0">' . self::fabric() . '</td></tr>'
            . '<tr><td style="background:#141D33;padding:18px 28px;font:12px/1.6 Arial,sans-serif;color:#b9b4c4">'
            . 'Nile Vijana Creatives · Accra, Ghana<br>Music, beats and studios for African creators.<br>'
            . '<a href="' . self::e($site) . '" style="color:#F0A232">nilevijanacreatives.com</a> · © ' . $year
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** The website's fabric strip, drawn with table cells so every email app shows it. */
    private static function fabric(): string
    {
        $pattern = ['#E03F19', '#51312A', '#FFD00D', '#51312A', '#E03F19', '#7A0B47', '#F0A232', '#7A0B47'];
        $cells = '';
        for ($i = 0; $i < 24; $i++) {
            $cells .= '<td style="height:8px;background:' . $pattern[$i % count($pattern)] . '"></td>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>';
    }

    private static function button(string $label, string $url): string
    {
        return '<p style="margin:26px 0"><a href="' . self::e($url) . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#7A0B47;color:#ffffff;font:700 15px/1 Arial,sans-serif;text-decoration:none">'
            . self::e($label) . '</a></p>';
    }

    private static function list(array $items): string
    {
        $out = '<ul style="margin:0 0 16px;padding-left:20px">';
        foreach ($items as $item) {
            $out .= '<li style="margin:0 0 8px;font:15px/1.6 Arial,sans-serif;color:#2b2631">' . $item . '</li>';
        }
        return $out . '</ul>';
    }

    private static function h1(): string
    {
        return 'margin:0 0 14px;font:700 22px/1.3 Arial,sans-serif;color:#141D33';
    }

    private static function p(): string
    {
        return 'margin:0 0 14px;font:15px/1.6 Arial,sans-serif;color:#2b2631';
    }

    private static function small(): string
    {
        return 'margin:18px 0 0;font:13px/1.6 Arial,sans-serif;color:#6b6470';
    }

    private static function first(string $name): string
    {
        $first = trim(strtok(trim($name), ' ') ?: '');
        return $first !== '' ? $first : 'there';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
