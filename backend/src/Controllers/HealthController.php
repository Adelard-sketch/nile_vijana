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
            'genres'    => ['Afrobeat', 'Amapiano', 'Afro-pop', 'Hip hop', 'Drill', 'R&B', 'Gospel', 'Bongo Flava', 'Dancehall', 'Reggae', 'Traditional', 'Other'],
            'moods'     => ['Happy', 'Romantic', 'Energetic', 'Chill', 'Sad', 'Dark', 'Inspirational', 'Party'],
            'languages' => ['Kinyarwanda', 'Swahili', 'English', 'French', 'Luganda', 'Other'],
            'keys'      => ['C maj', 'C min', 'C# maj', 'C# min', 'D maj', 'D min', 'D# maj', 'D# min', 'E maj', 'E min', 'F maj', 'F min',
                            'F# maj', 'F# min', 'G maj', 'G min', 'G# maj', 'G# min', 'A maj', 'A min', 'A# maj', 'A# min', 'B maj', 'B min'],
            'license_tiers' => array_map(static fn ($k, $v) => ['tier' => $k, 'name' => $v, 'default_stream_cap' => LicensePdf::DEFAULT_STREAM_CAPS[$k]],
                array_keys(LicensePdf::TIER_NAMES), LicensePdf::TIER_NAMES),
            'mobile_money_providers' => [
                ['code' => 'MTN_MOMO_RWA', 'name' => 'MTN MoMo Rwanda', 'prefixes' => ['078', '079']],
                ['code' => 'AIRTEL_RWA',   'name' => 'Airtel Money Rwanda', 'prefixes' => ['072', '073']],
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
