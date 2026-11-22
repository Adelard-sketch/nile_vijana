<?php
/**
 * mailer.php — Standalone SMTP mailer using Gmail.
 * Loaded by core.php. Credentials come from gmail_credential.php.
 *
 * Usage:
 *   $ok = Mailer::send('user@example.com', 'Your OTP code', '<p>Your code is <b>123456</b></p>');
 */

declare(strict_types=1);

class Mailer
{
    /**
     * Send an email. Returns true on success, false on failure.
     */
    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("[Mailer] Invalid address: $to");
            return false;
        }

        $text    = $text ?? self::htmlToText($html);
        $message = self::buildMessage($to, $subject, $html, $text);

        try {
            self::smtp($to, $message);
            return true;
        } catch (Throwable $e) {
            error_log('[Mailer] FAILED: ' . $e->getMessage() . " | to=$to subject=$subject");
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Message builder
    // -------------------------------------------------------------------------

    private static function buildMessage(string $to, string $subject, string $html, string $text): string
    {
        $from     = defined('GMAIL_FROM')      ? GMAIL_FROM      : GMAIL_USERNAME;
        $fromName = defined('GMAIL_FROM_NAME') ? GMAIL_FROM_NAME : 'Nile Vijana Creatives';
        $boundary = 'nv-' . bin2hex(random_bytes(12));

        $headers = implode("\r\n", [
            'Date: '       . date('r'),
            'From: '       . self::encodeHeader($fromName) . " <$from>",
            "To: <$to>",
            'Subject: '    . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@nilevijanacreatives.com>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            'X-Mailer: Nile Vijana Creatives',
        ]);

        $body =
            "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "--$boundary--\r\n";

        return $headers . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace(
            ['#<br\s*/?>#i', '#</(p|div|h[1-6]|tr|li)>#i', '#<li[^>]*>#i'],
            ["\n", "\n\n", '- '],
            $html
        ) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }

    // -------------------------------------------------------------------------
    // SMTP client (speaks the protocol directly — no Composer needed)
    // -------------------------------------------------------------------------

    private static function smtp(string $to, string $message): void
    {
        $host       = defined('GMAIL_SMTP_HOST') ? GMAIL_SMTP_HOST : 'smtp.gmail.com';
        $port       = defined('GMAIL_SMTP_PORT') ? (int) GMAIL_SMTP_PORT : 587;
        $username   = defined('GMAIL_USERNAME')  ? GMAIL_USERNAME  : '';
        $password   = defined('GMAIL_APP_PASSWORD') ? GMAIL_APP_PASSWORD : '';
        $from       = defined('GMAIL_FROM')      ? GMAIL_FROM      : $username;
        $timeout    = 15;

        if ($username === '' || $password === '') {
            throw new RuntimeException('Gmail credentials are not defined in gmail_credential.php');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
                'peer_name'        => $host,
            ],
        ]);

        $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to $host:$port — $errstr");
        }
        stream_set_timeout($fp, $timeout);

        try {
            self::expect($fp, [220]);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            self::cmd($fp, $ehlo, [250]);

            // STARTTLS upgrade
            self::cmd($fp, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new RuntimeException('STARTTLS handshake failed');
            }
            self::cmd($fp, $ehlo, [250]);

            // AUTH LOGIN with Gmail App Password
            self::cmd($fp, 'AUTH LOGIN', [334]);
            self::cmd($fp, base64_encode($username), [334]);
            self::cmd($fp, base64_encode($password), [235], true);

            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            self::cmd($fp, "RCPT TO:<$to>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);

            // Dot-stuffing per RFC 5321
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? $message;
            $data = str_replace("\n", "\r\n", $data);
            self::cmd($fp, $data . "\r\n.", [250]);

            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    private static function cmd($fp, string $line, array $ok, bool $secret = false): string
    {
        if (fwrite($fp, $line . "\r\n") === false) {
            throw new RuntimeException('SMTP connection lost while sending');
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
            $hint = $after ? " after \"" . mb_substr($after, 0, 40) . '"' : '';
            throw new RuntimeException('SMTP error' . $hint . ': ' . trim($response ?: 'no response'));
        }
        return $response;
    }
}
