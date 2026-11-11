<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Services\Ledger;

/**
 * GET /api/dashboard/stats?range=30
 * One call for the creator/studio dashboard. Sections appear based on the user's roles.
 */
final class DashboardController extends Controller
{
    public function stats(Request $req): array
    {
        $uid = $req->userId();
        $days = (int) ($req->query['range'] ?? 30);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $out = [
            'range_days' => $days,
            'since'      => $since,
            'wallet'     => ['balance' => Ledger::balance($uid), 'currency' => config('money.currency')],
            'earnings'   => $this->earnings($uid, $since),
        ];

        if (Auth::hasRole($req->user, 'artist')) {
            $out['music'] = $this->music($uid, $since, $days);
        }
        if (Auth::hasRole($req->user, 'producer')) {
            $out['beats'] = $this->beats($uid, $since);
        }
        if (Auth::hasRole($req->user, 'studio_owner')) {
            $out['studio'] = $this->studio($uid, $since);
        }
        return $out;
    }

    private function earnings(int $uid, string $since): array
    {
        $rows = DB::all(
            "SELECT type, SUM(amount) AS total FROM ledger_entries
              WHERE account = 'user' AND user_id = ? AND created_at >= ? AND type IN ('sale','tip','booking_release')
              GROUP BY type",
            [$uid, $since]
        );
        $by = array_column($rows, 'total', 'type');
        return [
            'sales'    => (int) ($by['sale'] ?? 0),
            'tips'     => (int) ($by['tip'] ?? 0),
            'bookings' => (int) ($by['booking_release'] ?? 0),
            'total'    => (int) array_sum($by),
        ];
    }

    private function music(int $uid, string $since, int $days): array
    {
        $base = 'FROM play_events p JOIN tracks t ON t.id = p.track_id WHERE t.creator_id = ? AND p.created_at >= ?';
        $params = [$uid, $since];

        $plays = (int) DB::value("SELECT COUNT(*) $base AND p.counted = 1", $params);
        $listeners = (int) DB::value("SELECT COUNT(DISTINCT COALESCE(CONCAT('u', p.listener_id), p.session_id)) $base", $params);
        $completion = DB::value(
            "SELECT AVG(LEAST(p.seconds_played / t.duration_sec, 1)) $base AND t.duration_sec > 0",
            $params
        );

        $daily = array_column(DB::all("SELECT DATE(p.created_at) AS d, COUNT(*) AS n $base AND p.counted = 1 GROUP BY DATE(p.created_at)", $params), 'n', 'd');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[] = ['date' => $d, 'plays' => (int) ($daily[$d] ?? 0)];
        }

        return [
            'plays'            => $plays,
            'unique_listeners' => $listeners,
            'avg_completion'   => $completion !== null ? round((float) $completion, 2) : null,   // 0.75 = listened to 75% on average
            'followers'        => (int) DB::value('SELECT COUNT(*) FROM follows WHERE creator_id = ?', [$uid]),
            'new_followers'    => (int) DB::value('SELECT COUNT(*) FROM follows WHERE creator_id = ? AND created_at >= ?', [$uid, $since]),
            'new_likes'        => (int) DB::value('SELECT COUNT(*) FROM likes l JOIN tracks t ON t.id = l.track_id WHERE t.creator_id = ? AND l.created_at >= ?', [$uid, $since]),
            'top_cities'       => DB::all("SELECT COALESCE(p.city, 'Unknown') AS city, COUNT(*) AS plays $base AND p.counted = 1 GROUP BY COALESCE(p.city, 'Unknown') ORDER BY plays DESC LIMIT 5", $params),
            'devices'          => DB::all("SELECT COALESCE(p.device, 'Unknown') AS device, COUNT(*) AS plays $base AND p.counted = 1 GROUP BY COALESCE(p.device, 'Unknown') ORDER BY plays DESC LIMIT 5", $params),
            'daily_plays'      => $series,
            'top_tracks'       => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'plays' => (int) $r['plays'], 'all_time_plays' => (int) $r['play_count']], DB::all(
                "SELECT t.id, t.title, t.play_count, COUNT(p.id) AS plays
                   FROM tracks t LEFT JOIN play_events p ON p.track_id = t.id AND p.counted = 1 AND p.created_at >= ?
                  WHERE t.creator_id = ? AND t.status = 'live'
                  GROUP BY t.id, t.title, t.play_count ORDER BY plays DESC, t.play_count DESC LIMIT 10",
                [$since, $uid]
            )),
            'songs_sold'       => (int) DB::value(
                "SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                  WHERE oi.seller_id = ? AND oi.item_type = 'track' AND o.status = 'paid' AND o.paid_at >= ?",
                [$uid, $since]
            ),
        ];
    }

    private function beats(int $uid, string $since): array
    {
        $byTier = DB::all(
            "SELECT oi.tier, COUNT(*) AS sold, SUM(oi.price - oi.commission) AS earned
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.seller_id = ? AND oi.item_type = 'license' AND o.status = 'paid' AND o.paid_at >= ?
              GROUP BY oi.tier",
            [$uid, $since]
        );
        return [
            'live_beats'     => (int) DB::value("SELECT COUNT(*) FROM beats WHERE producer_id = ? AND status = 'live'", [$uid]),
            'preview_plays'  => (int) DB::value("SELECT COALESCE(SUM(play_count), 0) FROM beats WHERE producer_id = ?", [$uid]),
            'licences_sold'  => (int) array_sum(array_column($byTier, 'sold')),
            'by_tier'        => array_map(static fn ($r) => ['tier' => $r['tier'], 'sold' => (int) $r['sold'], 'earned' => (int) $r['earned']], $byTier),
            'top_beats'      => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'sold' => (int) $r['sold'], 'preview_plays' => (int) $r['play_count']], DB::all(
                "SELECT b.id, b.title, b.play_count,
                        (SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                          WHERE oi.beat_id = b.id AND oi.item_type = 'license' AND o.status = 'paid') AS sold
                   FROM beats b WHERE b.producer_id = ? AND b.status <> 'removed'
                  ORDER BY sold DESC, b.play_count DESC LIMIT 5",
                [$uid]
            )),
        ];
    }

    private function studio(int $uid, string $since): array
    {
        $base = 'FROM bookings b JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id WHERE s.owner_id = ?';
        return [
            'bookings_by_status' => array_map(static fn ($r) => ['status' => $r['status'], 'count' => (int) $r['n']],
                DB::all("SELECT b.status, COUNT(*) AS n $base AND b.created_at >= ? GROUP BY b.status", [$uid, $since])),
            'hours_booked'       => (int) DB::value("SELECT COALESCE(SUM(b.hours), 0) $base AND b.status IN ('confirmed','completed') AND b.start_at >= ?", [$uid, $since]),
            'in_escrow'          => (int) DB::value(
                "SELECT COALESCE(SUM(le.amount), 0) FROM ledger_entries le
                   JOIN bookings b ON le.ref_type = 'booking' AND le.ref_id = b.id
                   JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
                  WHERE le.account = 'escrow' AND s.owner_id = ?",
                [$uid]
            ),
            'upcoming'           => DB::all(
                "SELECT b.id, b.start_at, b.hours, b.status, r.name AS room, u.name AS artist, u.phone AS artist_phone
                   FROM bookings b JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
                   JOIN users u ON u.id = b.artist_id
                  WHERE s.owner_id = ? AND b.status = 'confirmed' AND b.start_at >= NOW() ORDER BY b.start_at LIMIT 10",
                [$uid]
            ),
        ];
    }
}
