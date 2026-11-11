<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Generates the beat licence PDF that the buyer downloads after paying.
 * Uses a tiny built-in PDF writer (no library needed).
 *
 * The licence wording is a TEMPLATE for the MVP: have a lawyer review it
 * before using it for real sales.
 */
final class LicensePdf
{
    public const TIER_NAMES = [
        'mp3_lease' => 'MP3 Lease',
        'wav_lease' => 'WAV Lease',
        'unlimited' => 'Unlimited Lease',
        'exclusive' => 'Exclusive Rights',
    ];

    public const DEFAULT_STREAM_CAPS = [
        'mp3_lease' => 50000,
        'wav_lease' => 200000,
        'unlimited' => null,
        'exclusive' => null,
    ];

    /** @param array $d licence data: number, date, producer, buyer_name, buyer_phone, beat_title, bpm, key, tier, stream_cap, price, currency, order_id, has_stems */
    public static function render(array $d): string
    {
        $tier = $d['tier'];
        $cap = $d['stream_cap'] ?? self::DEFAULT_STREAM_CAPS[$tier] ?? null;
        $capText = $cap ? number_format((int) $cap) . ' audio streams in total across all platforms' : 'unlimited audio streams';
        $files = match ($tier) {
            'mp3_lease' => 'MP3',
            'wav_lease', 'unlimited' => 'MP3 and WAV',
            'exclusive' => $d['has_stems'] ? 'MP3, WAV and track stems' : 'MP3 and WAV',
        };

        $lines = [];
        $lines[] = ['h', 'Licence details'];
        foreach ([
            'Licence number' => $d['number'],
            'Date'           => $d['date'],
            'Licence type'   => self::TIER_NAMES[$tier] ?? $tier,
            'Beat'           => $d['beat_title'] . self::beatInfo($d),
            'Licensor'       => $d['producer'] . ' (producer)',
            'Licensee'       => $d['buyer_name'] . ($d['buyer_phone'] ? ' (+' . $d['buyer_phone'] . ')' : ''),
            'Price paid'     => number_format((int) $d['price']) . ' ' . $d['currency'],
            'Order'          => '#' . $d['order_id'],
            'Files included' => $files,
        ] as $k => $v) {
            $lines[] = ['kv', $k, (string) $v];
        }

        $lines[] = ['h', 'What the licensee may do'];
        if ($tier === 'exclusive') {
            $terms = [
                'The licensee receives exclusive rights to use the beat in one new song, with ' . $capText . ', unlimited music videos and paid performances.',
                'The beat is removed from sale on Nile Vijana Creatives. Leases sold before this purchase stay valid.',
                'The producer keeps credit as composer of the instrumental.',
            ];
        } else {
            $terms = [
                'This is a NON-EXCLUSIVE licence. The producer keeps ownership and may license the beat to others.',
                'The licensee may record one new song on the beat and distribute it with ' . $capText . '.',
                $tier === 'unlimited'
                    ? 'Unlimited music videos and paid live performances are allowed.'
                    : 'One music video and non-paid live performances are allowed.',
            ];
        }
        $terms[] = 'The song title or description must credit the producer: "Prod. by ' . $d['producer'] . '".';
        $terms[] = 'The licensee may not resell, share or re-license the beat itself, or register the instrumental alone with content-ID systems.';
        $terms[] = 'This licence cannot be transferred to another person.';
        foreach ($terms as $t) {
            $lines[] = ['bullet', $t];
        }

        $lines[] = ['h', 'General'];
        $lines[] = ['p', 'This agreement is governed by the laws of the Republic of Rwanda. Proof of purchase is held by Nile Vijana Creatives under the order number above.'];
        $lines[] = ['small', 'Template licence generated for the Nile Vijana Creatives MVP. Have a qualified lawyer review it before commercial use.'];

        return self::build('Beat Licence Agreement', self::TIER_NAMES[$tier] ?? $tier, $lines);
    }

    private static function beatInfo(array $d): string
    {
        $bits = array_filter([$d['bpm'] ? $d['bpm'] . ' BPM' : null, $d['key'] ?: null]);
        return $bits ? ' (' . implode(', ', $bits) . ')' : '';
    }

    // ------------------------------------------------------------------
    // Minimal PDF writer: one A4 page, Helvetica, WinAnsi encoding.
    // ------------------------------------------------------------------
    private static function build(string $title, string $subtitle, array $lines): string
    {
        $c = [];
        // Berry header band with the brand name.
        $c[] = '0.478 0.043 0.278 rg 0 762 595 80 re f';
        $c[] = self::text(50, 808, 'F2', 20, $title, '1 1 1');
        $c[] = self::text(50, 784, 'F1', 12, 'Nile Vijana Creatives  |  ' . $subtitle, '0.941 0.635 0.196');

        $y = 735;
        foreach ($lines as $line) {
            switch ($line[0]) {
                case 'h':
                    $y -= 12;
                    $c[] = self::text(50, $y, 'F2', 13, $line[1], '0.078 0.114 0.2');
                    $y -= 20;
                    break;
                case 'kv':
                    $c[] = self::text(50, $y, 'F2', 10.5, $line[1], '0.3 0.3 0.3');
                    foreach (self::wrap($line[2], 62) as $i => $part) {
                        $c[] = self::text(185, $y - $i * 14, 'F1', 10.5, $part, '0.04 0.04 0.05');
                    }
                    $y -= 16 + 14 * (count(self::wrap($line[2], 62)) - 1);
                    break;
                case 'bullet':
                    foreach (self::wrap($line[1], 88) as $i => $part) {
                        $c[] = self::text($i === 0 ? 50 : 62, $y, 'F1', 10.5, ($i === 0 ? '-  ' : '') . $part, '0.04 0.04 0.05');
                        $y -= 14;
                    }
                    $y -= 4;
                    break;
                case 'p':
                    foreach (self::wrap($line[1], 92) as $part) {
                        $c[] = self::text(50, $y, 'F1', 10.5, $part, '0.04 0.04 0.05');
                        $y -= 14;
                    }
                    $y -= 6;
                    break;
                case 'small':
                    $y = min($y, 70);
                    foreach (self::wrap($line[1], 110) as $part) {
                        $c[] = self::text(50, $y, 'F1', 8.5, $part, '0.45 0.45 0.45');
                        $y -= 11;
                    }
                    break;
            }
        }
        $stream = implode("\n", $c);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }

    private static function text(float $x, float $y, string $font, float $size, string $s, string $rgb): string
    {
        return sprintf('BT %s rg /%s %s Tf %s %s Td (%s) Tj ET', $rgb, $font, $size, $x, $y, self::escape($s));
    }

    private static function escape(string $s): string
    {
        $s = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: $s;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    private static function wrap(string $s, int $width): array
    {
        return explode("\n", wordwrap($s, $width, "\n", true));
    }
}
