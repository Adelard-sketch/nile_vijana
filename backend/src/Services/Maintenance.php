<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Services\Payments\PaymentService;

/**
 * Housekeeping run by bin/worker.php every minute.
 * It is the safety net for everything asynchronous: if a callback never
 * arrives or a page is closed while paying, this still settles the money.
 */
final class Maintenance
{
    public static function run(): array
    {
        $stats = ['jobs' => Jobs::runQueued(10)];

        // 1. Check payments still waiting for the buyer's PIN.
        $pending = DB::all(
            "SELECT * FROM payments WHERE status IN ('initiated','accepted') AND created_at < ? ORDER BY id LIMIT 50",
            [now(-10)]
        );
        foreach ($pending as $p) {
            PaymentService::refresh($p);
        }
        $stats['payments_checked'] = count($pending);

        // 2. Release booking slots whose hold ran out without payment.
        $stats['holds_expired'] = DB::run(
            "UPDATE bookings SET status = 'expired', cancelled_by = 'system'
              WHERE status = 'held' AND hold_expires_at < NOW()"
        )->rowCount();

        // 3. Expire orders nobody paid for.
        $stats['orders_expired'] = DB::run(
            "UPDATE orders SET status = 'expired' WHERE status = 'pending' AND created_at < ?",
            [now(-60 * (int) config('money.order_expiry_minutes', 30))]
        )->rowCount();

        // 4. Check payouts being sent.
        $processing = DB::all("SELECT * FROM payouts WHERE status = 'processing' ORDER BY id LIMIT 50");
        foreach ($processing as $p) {
            PayoutService::refresh($p);
        }
        $stats['payouts_checked'] = count($processing);

        // 5. Tidy up old sign-in data.
        DB::run('DELETE FROM auth_tokens WHERE expires_at < NOW()');
        DB::run('DELETE FROM otp_codes WHERE created_at < ?', [now(-86400)]);

        return $stats;
    }
}
