<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Sends email: welcome messages, sign-in codes, password reset codes and Q&A answers.
 *
 * Drivers (config mail.driver, set MAIL_DRIVER in backend/.env):
 *   log   writes each message to storage/logs/mail/*.eml (development, nothing leaves the machine)
 *   smtp  sends through any SMTP server (Gmail/Google Workspace, Zoho, Brevo, Mailgun, cPanel mail)
 *
 * No Composer packages: the SMTP client below speaks the protocol directly
 * (EHLO, STARTTLS or SSL, AUTH LOGIN, MAIL FROM, RCPT TO, DATA).
 * send() never throws; it returns false and logs to storage/logs/mail.log when sending fails.
 */
final class Mailer
{
    /** @var array<int, array{to:string, subject:string, html:string, text:string}> sent in this request (tests read it) */
    public static array $sent = [];

    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            app_log('mail', 'not sent: invalid address', ['to' => $to, 'subject' => $subject]);
            return false;
        }
        $text ??= self::toText($html);
        $message = self::build($to, $subject, $html, $text);
        self::$sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];

        $driver = (string) config('mail.driver', 'log');
        try {
            if ($driver === 'smtp') {
                self::smtp($to, $message);
            } else {
                self::log($to, $subject, $message);
            }
            app_log('mail', "sent ($driver)", ['to' => $to, 'subject' => $subject]);
            return true;
        } catch (\Throwable $e) {
            app_log('mail', "FAILED ($driver): " . $e->getMessage(), ['to' => $to, 'subject' => $subject]);
            return false;
        }
    }

    /** True when emails really leave the server (used to decide whether to show dev codes). */
    public static function delivers(): bool
    {
        return (string) config('mail.driver', 'log') === 'smtp';
    }

    /* ------------------------------------------------------------------ message */

    private static function build(string $to, string $subject, string $html, string $text): string
    {
        $from = (string) config('mail.from', 'no-reply@nilevijanacreatives.com');
        $fromName = (string) config('mail.from_name', 'Nile Vijana Creatives');
        $replyTo = (string) config('mail.reply_to', '');
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $boundary = 'nv-' . bin2hex(random_bytes(12));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader($fromName) . " <$from>",
            "To: <$to>",
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            'X-Mailer: Nile Vijana Creatives',
        ];
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = "Reply-To: <$replyTo>";
        }

        $body = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "--$boundary--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    private static function toText(string $html): string
    {
        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|h[1-6]|tr|li)>#i', '#<li[^>]*>#i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    /* ------------------------------------------------------------------ drivers */

    private static function log(string $to, string $subject, string $message): void
    {
        $dir = BASE_PATH . '/storage/logs/mail';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage/logs/mail');
        }
        $name = date('Ymd-His') . '-' . slugify($subject) . '-' . bin2hex(random_bytes(3)) . '.eml';
        if (file_put_contents("$dir/$name", $message) === false) {
            throw new \RuntimeException('Cannot write the email file');
        }
    }

    private static function smtp(string $to, string $message): void
    {
        $c = (array) config('mail.smtp', []);
        $host = (string) ($c['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('SMTP_HOST is not set in backend/.env');
        }
        $port = (int) ($c['port'] ?? 587);
        $encryption = strtolower((string) ($c['encryption'] ?? 'tls'));
        $timeout = (int) ($c['timeout'] ?? 15);
        $from = (string) config('mail.from');

        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new \RuntimeException("Cannot connect to $host:$port ($errstr)");
        }
        stream_set_timeout($fp, $timeout);

        try {
            self::expect($fp, [220]);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            self::command($fp, $ehlo, [250]);
            if ($encryption === 'tls') {
                self::command($fp, 'STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (!stream_socket_enable_crypto($fp, true, $crypto)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                self::command($fp, $ehlo, [250]);
            }
            $user = (string) ($c['username'] ?? '');
            if ($user !== '') {
                self::command($fp, 'AUTH LOGIN', [334]);
                self::command($fp, base64_encode($user), [334]);
                self::command($fp, base64_encode((string) ($c['password'] ?? '')), [235], true);
            }
            self::command($fp, "MAIL FROM:<$from>", [250]);
            self::command($fp, "RCPT TO:<$to>", [250, 251]);
            self::command($fp, 'DATA', [354]);
            // Dot-stuffing: a line that starts with "." gets a second one.
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? $message;
            $data = str_replace("\n", "\r\n", $data);
            self::command($fp, $data . "\r\n.", [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    private static function command($fp, string $line, array $ok, bool $secret = false): string
    {
        if (fwrite($fp, $line . "\r\n") === false) {
            throw new \RuntimeException('SMTP connection lost');
        }
        return self::expect($fp, $ok, $secret ? '[hidden]' : strtok($line, "\r\n"));
    }

    private static function expect($fp, array $ok, ?string $after = null): string
    {
        $response = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            $what = $after ? " after \"" . mb_substr($after, 0, 40) . '"' : '';
            throw new \RuntimeException('SMTP error' . $what . ': ' . trim($response ?: 'no response'));
        }
        return $response;
    }
}
