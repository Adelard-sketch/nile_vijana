<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Numbers for the dashboards, always computed from real platform records:
 * the money ledger, paid orders, play events, bookings and payouts.
 * Nothing here is estimated or stored separately, so a figure can always be
 * traced back to the rows it came from.
 */
final class Analytics
{
    /** The last $months calendar months, oldest first: ['2026-06' => 'Jun 2026', ...] */
    public static function months(int $months = 12): array
    {
        $out = [];
        $first = strtotime(date('Y-m-01'));
        for ($i = $months - 1; $i >= 0; $i--) {
            $t = strtotime("-$i months", $first);
            $out[date('Y-m', $t)] = date('M Y', $t);
        }
        return $out;
    }

    /** Start of the oldest month in months($n). */
    public static function monthsStart(int $months = 12): string
    {
        return date('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
    }

    /** Fill a [key => value] list into a continuous series over $keys. */
    public static function series(array $keys, array $values, string $valueName = 'value'): array
    {
        $out = [];
        foreach ($keys as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, $valueName => (int) ($values[$key] ?? 0)];
        }
        return $out;
    }

    /** Calendar days from $since to today: ['2026-11-01' => '1 Nov', ...] */
    public static function days(int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $t = strtotime("-$i days");
            $out[date('Y-m-d', $t)] = date('j M', $t);
        }
        return $out;
    }

    /**
     * Wallet and earnings summary for any creator or studio owner.
     *  balance          what can be withdrawn now
     *  pending_payouts  withdrawals requested or being sent (already taken out of the balance)
     *  pending_sales    sales waiting for the buyer's mobile money confirmation (not earned yet)
     */
    public static function wallet(int $uid, int $months = 12): array
    {
        $since = self::monthsStart($months);
        $byType = array_column(DB::all(
            "SELECT type, SUM(amount) AS total FROM ledger_entries
              WHERE account = 'user' AND user_id = ? AND type IN ('sale','tip','booking_release')
              GROUP BY type",
            [$uid]
        ), 'total', 'type');
        $monthly = [];
        foreach (DB::all(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, type, SUM(amount) AS total FROM ledger_entries
              WHERE account = 'user' AND user_id = ? AND type IN ('sale','tip','booking_release') AND created_at >= ?
              GROUP BY DATE_FORMAT(created_at, '%Y-%m'), type",
            [$uid, $since]
        ) as $r) {
            $monthly[$r['m']][$r['type']] = (int) $r['total'];
        }
        $series = [];
        foreach (self::months($months) as $key => $label) {
            $m = $monthly[$key] ?? [];
            $series[] = [
                'key'      => $key,
                'label'    => $label,
                'sales'    => $m['sale'] ?? 0,
                'tips'     => $m['tip'] ?? 0,
                'bookings' => $m['booking_release'] ?? 0,
                'total'    => array_sum($m),
            ];
        }
        $payouts = array_column(DB::all(
            "SELECT status, SUM(amount) AS total, COUNT(*) AS n FROM payouts WHERE user_id = ? GROUP BY status",
            [$uid]
        ), null, 'status');

        return [
            'currency'        => (string) config('money.currency', 'GHS'),
            'balance'         => Ledger::balance($uid),
            'pending_payouts' => (int) (($payouts['requested']['total'] ?? 0) + ($payouts['processing']['total'] ?? 0)),
            'withdrawn'       => (int) ($payouts['completed']['total'] ?? 0),
            'pending_sales'   => self::pendingSales($uid),
            'min_payout'      => (int) config('money.min_payout', 1000),
            'lifetime'        => [
                'sales'    => (int) ($byType['sale'] ?? 0),
                'tips'     => (int) ($byType['tip'] ?? 0),
                'bookings' => (int) ($byType['booking_release'] ?? 0),
                'total'    => (int) array_sum($byType),
            ],
            'this_month'      => end($series)['total'] ?? 0,
            'monthly'         => $series,
        ];
    }

    /** Net value (after commission) of this seller's items in orders still waiting for payment. */
    public static function pendingSales(int $uid): int
    {
        return (int) DB::value(
            "SELECT COALESCE(SUM(oi.price - oi.commission), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.seller_id = ? AND o.status = 'pending' AND o.created_at >= ?",
            [$uid, now(-60 * (int) config('money.order_expiry_minutes', 30))]
        );
    }

    /** Wallet history with readable labels. */
    public static function transactions(int $uid, int $limit = 20): array
    {
        $labels = [
            'sale' => 'Sale', 'tip' => 'Tip', 'booking_release' => 'Studio session', 'booking_refund' => 'Booking refund',
            'refund' => 'Refund', 'payout' => 'Withdrawal', 'payout_reversal' => 'Withdrawal returned',
        ];
        return array_map(static fn ($r) => [
            'id'         => (int) $r['id'],
            'type'       => $r['type'],
            'label'      => $labels[$r['type']] ?? ucfirst(str_replace('_', ' ', $r['type'])),
            'amount'     => (int) $r['amount'],
            'memo'       => $r['memo'],
            'created_at' => $r['created_at'],
        ], Ledger::history($uid, $limit));
    }

    /** Paid sales per month for a seller (gross price and net earnings) — e.g. licence revenue. */
    public static function monthlySales(int $uid, string $itemType, int $months = 12): array
    {
        $rows = array_column(DB::all(
            "SELECT DATE_FORMAT(o.paid_at, '%Y-%m') AS m, COUNT(*) AS n, SUM(oi.price - oi.commission) AS net
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.seller_id = ? AND oi.item_type = ? AND o.status = 'paid' AND o.paid_at >= ?
              GROUP BY DATE_FORMAT(o.paid_at, '%Y-%m')",
            [$uid, $itemType, self::monthsStart($months)]
        ), null, 'm');
        $out = [];
        foreach (self::months($months) as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'sold' => (int) ($rows[$key]['n'] ?? 0), 'earned' => (int) ($rows[$key]['net'] ?? 0)];
        }
        return $out;
    }

    /** Platform-wide: gross sales, booking money and commission per month (admin). */
    public static function platformMonthly(int $months = 12): array
    {
        $since = self::monthsStart($months);
        $sales = array_column(DB::all(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS m, SUM(total) AS t FROM orders
              WHERE status = 'paid' AND paid_at >= ? GROUP BY DATE_FORMAT(paid_at, '%Y-%m')",
            [$since]
        ), 't', 'm');
        $bookings = array_column(DB::all(
            "SELECT DATE_FORMAT(completed_at, '%Y-%m') AS m, SUM(amount) AS t FROM payments
              WHERE purpose <> 'order' AND status = 'completed' AND completed_at >= ? GROUP BY DATE_FORMAT(completed_at, '%Y-%m')",
            [$since]
        ), 't', 'm');
        $commission = array_column(DB::all(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, SUM(amount) AS t FROM ledger_entries
              WHERE account = 'platform' AND created_at >= ? GROUP BY DATE_FORMAT(created_at, '%Y-%m')",
            [$since]
        ), 't', 'm');
        $out = [];
        foreach (self::months($months) as $key => $label) {
            $out[] = [
                'key'        => $key,
                'label'      => $label,
                'sales'      => (int) ($sales[$key] ?? 0),
                'bookings'   => (int) ($bookings[$key] ?? 0),
                'commission' => (int) ($commission[$key] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Recent things that happened on the platform, newest first (admin activity feed).
     * Built from the real tables, so every entry links to a record.
     */
    public static function activity(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $events = [];
        $add = static function (array $rows, callable $map) use (&$events): void {
            foreach ($rows as $r) {
                $events[] = $map($r);
            }
        };

        $add(DB::all("SELECT id, name, created_at FROM users ORDER BY id DESC LIMIT $limit"), static fn ($r) => [
            'type' => 'user', 'icon' => 'user', 'at' => $r['created_at'], 'ref' => (int) $r['id'],
            'text' => "{$r['name']} joined",
        ]);
        foreach (['tracks' => ['creator_id', 'song'], 'beats' => ['producer_id', 'beat']] as $table => [$owner, $label]) {
            $add(DB::all(
                "SELECT x.id, x.title, x.status, COALESCE(x.submitted_at, x.created_at) AS at, cp.stage_name
                   FROM $table x LEFT JOIN creator_profiles cp ON cp.user_id = x.$owner
                  ORDER BY COALESCE(x.submitted_at, x.created_at) DESC LIMIT $limit"
            ), static fn ($r) => [
                'type' => $label, 'icon' => 'upload', 'at' => $r['at'], 'ref' => (int) $r['id'], 'status' => $r['status'],
                'text' => ($r['stage_name'] ?: 'A creator') . " submitted the $label \"{$r['title']}\"",
            ]);
            $add(DB::all(
                "SELECT x.id, x.title, x.status, x.reviewed_at, u.name AS admin FROM $table x
                   LEFT JOIN users u ON u.id = x.reviewed_by
                  WHERE x.reviewed_at IS NOT NULL AND x.status IN ('live','rejected')
                  ORDER BY x.reviewed_at DESC LIMIT $limit"
            ), static fn ($r) => [
                'type' => $label, 'icon' => $r['status'] === 'live' ? 'check' : 'x', 'at' => $r['reviewed_at'], 'ref' => (int) $r['id'],
                'status' => $r['status'],
                'text' => ($r['admin'] ?: 'An admin') . ($r['status'] === 'live' ? ' approved' : ' rejected') . " the $label \"{$r['title']}\"",
            ]);
        }
        $add(DB::all(
            "SELECT o.id, o.total, o.paid_at, u.name FROM orders o JOIN users u ON u.id = o.buyer_id
              WHERE o.status = 'paid' ORDER BY o.paid_at DESC LIMIT $limit"
        ), static fn ($r) => [
            'type' => 'order', 'icon' => 'cart', 'at' => $r['paid_at'], 'ref' => (int) $r['id'], 'amount' => (int) $r['total'],
            'text' => "{$r['name']} paid order #{$r['id']}",
        ]);
        $add(DB::all(
            "SELECT b.id, b.status, b.created_at, s.name AS studio, u.name FROM bookings b
               JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id JOIN users u ON u.id = b.artist_id
              WHERE b.status NOT IN ('held','expired') ORDER BY b.id DESC LIMIT $limit"
        ), static fn ($r) => [
            'type' => 'booking', 'icon' => 'calendar', 'at' => $r['created_at'], 'ref' => (int) $r['id'], 'status' => $r['status'],
            'text' => "{$r['name']} booked {$r['studio']} (#{$r['id']}, {$r['status']})",
        ]);
        $add(DB::all(
            "SELECT s.id, s.name, s.status, s.created_at FROM studios s ORDER BY s.id DESC LIMIT $limit"
        ), static fn ($r) => [
            'type' => 'studio', 'icon' => 'studio', 'at' => $r['created_at'], 'ref' => (int) $r['id'], 'status' => $r['status'],
            'text' => "Studio \"{$r['name']}\" was listed",
        ]);
        $add(DB::all(
            "SELECT p.id, p.amount, p.status, p.created_at, u.name FROM payouts p JOIN users u ON u.id = p.user_id
              ORDER BY p.id DESC LIMIT $limit"
        ), static fn ($r) => [
            'type' => 'payout', 'icon' => 'wallet', 'at' => $r['created_at'], 'ref' => (int) $r['id'], 'amount' => (int) $r['amount'],
            'status' => $r['status'], 'text' => "{$r['name']} requested a withdrawal",
        ]);

        $events = array_filter($events, static fn ($e) => !empty($e['at']));
        usort($events, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice(array_values($events), 0, $limit);
    }
}
