<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Services\LicensePdf;

final class HealthController extends Controller
{
    /** GET /api/health — is the API up and can it reach the database? */
    public function health(Request $req): array
    {
        $dbOk = true;
        try {
            DB::value('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        return [
            'ok'       => $dbOk,
            'app'      => config('app.name'),
            'time'     => date('c'),
            'database' => $dbOk ? 'connected' : 'not reachable - check config/config.php',
            'payments' => config('payments.gateway'),
        ];
    }

    /** GET /api/meta — lists for the frontend's dropdowns. */
    public function meta(Request $req): array
    {
        return [
            'currency'  => config('money.currency'),
            'currency_symbol' => config('money.symbol', 'GH₵'),
            'country'   => config('app.country', 'GH'),
            'payments'  => [
                'gateway'    => config('payments.gateway', 'paystack'),
                'ready'      => config('payments.gateway', 'paystack') !== 'paystack' || \App\Services\Payments\PaystackGateway::configured(),
                'test_mode'  => \App\Services\Payments\PaystackGateway::testMode(),
                // Public key only (safe in the browser); the secret key never leaves the server.
                'public_key' => (string) config('payments.paystack.public_key', ''),
            ],
            // true when the server makes tagged beat previews and streaming copies itself (FFmpeg).
            'makes_previews' => \App\Services\Media::enabled(),
            'min_payout' => (int) config('money.min_payout', 50),
            'min_tip'    => (int) config('money.min_tip', 5),
            'max_tip'    => (int) config('money.max_tip', 5000),
            'max_track_price'   => (int) config('money.max_track_price', 500),
            'min_license_price' => (int) config('money.min_license_price', 10),
            'max_license_price' => (int) config('money.max_license_price', 50000),
            'min_room_rate'     => (int) config('money.min_room_rate', 20),
            'max_room_rate'     => (int) config('money.max_room_rate', 10000),
            'uploads' => [
                'max_audio_mb' => (int) config('uploads.max_audio_mb', 50),
                'max_image_mb' => (int) config('uploads.max_image_mb', 5),
                'max_stems_mb' => (int) config('uploads.max_stems_mb', 200),
            ],
            'cities'    => ['Accra', 'Kumasi', 'Tamale', 'Takoradi', 'Cape Coast', 'Tema', 'Koforidua', 'Ho', 'Sunyani', 'Bolgatanga', 'Wa', 'Kasoa', 'Obuasi', 'Techiman'],
            'booking_response_hours' => (int) config('money.booking_response_hours', 48),
            'genres'    => ['Afrobeats', 'Highlife', 'Hiplife', 'Asakaa (Drill)', 'Amapiano', 'Afro-pop', 'Gospel', 'Hip hop', 'R&B', 'Reggae', 'Dancehall', 'Traditional', 'Other'],
            'moods'     => ['Happy', 'Romantic', 'Energetic', 'Chill', 'Sad', 'Dark', 'Inspirational', 'Party'],
            'languages' => ['English', 'Twi', 'Ga', 'Ewe', 'Fante', 'Dagbani', 'Hausa', 'Pidgin', 'French', 'Other'],
            'keys'      => ['C maj', 'C min', 'C# maj', 'C# min', 'D maj', 'D min', 'D# maj', 'D# min', 'E maj', 'E min', 'F maj', 'F min',
                            'F# maj', 'F# min', 'G maj', 'G min', 'G# maj', 'G# min', 'A maj', 'A min', 'A# maj', 'A# min', 'B maj', 'B min'],
            'license_tiers' => array_map(static fn ($k, $v) => ['tier' => $k, 'name' => $v, 'default_stream_cap' => LicensePdf::DEFAULT_STREAM_CAPS[$k]],
                array_keys(LicensePdf::TIER_NAMES), LicensePdf::TIER_NAMES),
            'mobile_money_providers' => [
                ['code' => 'MTN', 'name' => 'MTN Mobile Money', 'prefixes' => ['024', '025', '053', '054', '055', '059']],
                ['code' => 'VOD', 'name' => 'Telecel Cash',     'prefixes' => ['020', '050']],
                ['code' => 'ATL', 'name' => 'AirtelTigo Money', 'prefixes' => ['026', '027', '056', '057']],
            ],
            'commission' => [
                'sales'    => config('money.commission_sales'),
                'bookings' => config('money.commission_bookings'),
            ],
            'booking' => [
                'deposit_rate'      => config('money.deposit_rate'),
                'free_cancel_hours' => config('money.free_cancel_hours'),
                'hold_minutes'      => config('money.booking_hold_minutes'),
            ],
        ];
    }
}
