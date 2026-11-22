<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Services\Analytics;
use App\Services\BookingService;
use App\Services\Ledger;
use App\Support\Present;

/**
 * Dashboards for creators, producers, studios and managers.
 * Every figure comes from real records (ledger, paid orders, play events, bookings, payouts).
 *
 *   GET /api/dashboard/stats?range=30     compact summary (kept for older pages)
 *   GET /api/dashboard/wallet             balance, pending money, monthly earnings, transactions
 *   GET /api/dashboard/music?range=30     artists and choirs
 *   GET /api/dashboard/beats?range=30     producers
 *   GET /api/dashboard/studio?range=30    studio owners
 *   GET /api/dashboard/manager?range=30   managers (the artists they look after)
 */
final class DashboardController extends Controller
{
    private function range(Request $req): array
    {
        $days = (int) ($req->query['range'] ?? 30);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        return [$days, date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'))];
    }

    /** GET /api/dashboard/wallet */
    public function wallet(Request $req): array
    {
        $uid = $req->userId();
        $profile = DB::one('SELECT payout_phone, payout_provider FROM creator_profiles WHERE user_id = ?', [$uid]);
        return Analytics::wallet($uid) + [
            'payout_phone'    => $profile['payout_phone'] ?? null,
            'payout_provider' => $profile['payout_provider'] ?? null,
            'account_phone'   => $req->user['phone'] ?? null,
            'transactions'    => Analytics::transactions($uid, 25),
            'payouts'         => array_map([Present::class, 'payout'], DB::all('SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 15', [$uid])),
        ];
    }

    /** GET /api/dashboard/music?range=30 — songs, streams, earnings for an artist or choir. */
    public function musicDashboard(Request $req): array
    {
        $uid = $req->userId();
        [$days, $since] = $this->range($req);
        $status = array_column(DB::all("SELECT status, COUNT(*) AS n FROM tracks WHERE creator_id = ? AND status <> 'removed' GROUP BY status", [$uid]), 'n', 'status');

        $songs = DB::all(
            "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug,
                    (SELECT COUNT(*) FROM play_events p WHERE p.track_id = t.id AND p.counted = 1 AND p.created_at >= ?) AS plays_range,
                    (SELECT COUNT(*) FROM likes l WHERE l.track_id = t.id) AS like_count,
                    (SELECT COUNT(*) FROM saved_tracks s WHERE s.track_id = t.id) AS save_count,
                    (SELECT COUNT(*) FROM track_shares sh WHERE sh.track_id = t.id) AS share_count,
                    (SELECT COUNT(*) FROM track_downloads dl WHERE dl.track_id = t.id) AS download_count,
                    (SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.track_id = t.id AND oi.item_type = 'track' AND o.status = 'paid') AS sold,
                    (SELECT COALESCE(SUM(oi.price - oi.commission), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.track_id = t.id AND oi.item_type = 'track' AND o.status = 'paid') AS revenue
               FROM tracks t LEFT JOIN creator_profiles cp ON cp.user_id = t.creator_id
              WHERE t.creator_id = ? AND t.status <> 'removed'
              ORDER BY t.id DESC",
            [$since, $uid]
        );
        $list = array_map(static fn ($t) => Present::track($t) + [
            'shares'      => (int) $t['share_count'],
            'downloads'   => (int) $t['download_count'],
            'plays_range' => (int) $t['plays_range'],
            'sold'        => (int) $t['sold'],
            'revenue'     => (int) $t['revenue'],
        ], $songs);
        $live = array_values(array_filter($list, static fn ($t) => $t['status'] === 'live'));
        usort($live, static fn ($a, $b) => strcmp((string) ($b['released_at'] ?? ''), (string) ($a['released_at'] ?? '')));
        $top = $live;
        usort($top, static fn ($a, $b) => [$b['plays_range'], $b['plays']] <=> [$a['plays_range'], $a['plays']]);

        $base = 'FROM play_events p JOIN tracks t ON t.id = p.track_id WHERE t.creator_id = ? AND p.created_at >= ?';
        $daily = array_column(DB::all("SELECT DATE(p.created_at) AS d, COUNT(*) AS n $base AND p.counted = 1 GROUP BY DATE(p.created_at)", [$uid, $since]), 'n', 'd');
        $wallet = Analytics::wallet($uid);

        return [
            'range_days' => $days,
            'currency'   => $wallet['currency'],
            'totals'     => [
                'uploads'          => (int) array_sum($status),
                'live'             => (int) ($status['live'] ?? 0),
                'in_review'        => (int) (($status['in_review'] ?? 0) + ($status['processing'] ?? 0)),
                'rejected'         => (int) ($status['rejected'] ?? 0),
                'streams'          => (int) array_sum(array_column($list, 'plays')),
                'streams_range'    => (int) array_sum($daily),
                'listeners_range'  => (int) DB::value("SELECT COUNT(DISTINCT COALESCE(CONCAT('u', p.listener_id), p.session_id)) $base AND p.counted = 1", [$uid, $since]),
                'followers'        => (int) DB::value('SELECT COUNT(*) FROM follows WHERE creator_id = ?', [$uid]),
                'likes'            => (int) array_sum(array_column($list, 'likes')),
                'saves'            => (int) array_sum(array_column($list, 'saves')),
                'shares'           => (int) array_sum(array_column($list, 'shares')),
                'downloads'        => (int) array_sum(array_column($list, 'downloads')),
                'songs_sold'       => (int) array_sum(array_column($list, 'sold')),
                'earnings'         => $wallet['lifetime']['sales'] + $wallet['lifetime']['tips'],
                'balance'          => $wallet['balance'],
                'pending_earnings' => $wallet['pending_sales'],
                'pending_payouts'  => $wallet['pending_payouts'],
            ],
            'daily_streams'   => Analytics::series(Analytics::days($days), $daily, 'plays'),
            'monthly'         => array_map(static fn ($m) => ['key' => $m['key'], 'label' => $m['label'], 'sales' => $m['sales'], 'tips' => $m['tips'], 'total' => $m['sales'] + $m['tips']], $wallet['monthly']),
            'recent_releases' => array_slice($live, 0, 5),
            'top_songs'       => array_slice($top, 0, 5),
            'songs'           => $list,
            'top_cities'      => DB::all("SELECT COALESCE(p.city, 'Unknown') AS city, COUNT(*) AS plays $base AND p.counted = 1 GROUP BY COALESCE(p.city, 'Unknown') ORDER BY plays DESC LIMIT 5", [$uid, $since]),
            'transactions'    => Analytics::transactions($uid, 8),
        ];
    }

    /** GET /api/dashboard/beats?range=30 — beats, plays, licence sales and earnings for a producer. */
    public function beatsDashboard(Request $req): array
    {
        $uid = $req->userId();
        [$days, $since] = $this->range($req);
        $rows = DB::all(
            "SELECT b.*, cp.stage_name AS producer_name, cp.slug AS producer_slug,
                    (SELECT MIN(lt.price) FROM license_tiers lt WHERE lt.beat_id = b.id AND lt.active = 1) AS min_price,
                    (SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.beat_id = b.id AND oi.item_type = 'license' AND o.status = 'paid') AS sold,
                    (SELECT COALESCE(SUM(oi.price - oi.commission), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.beat_id = b.id AND oi.item_type = 'license' AND o.status = 'paid') AS revenue
               FROM beats b LEFT JOIN creator_profiles cp ON cp.user_id = b.producer_id
              WHERE b.producer_id = ? AND b.status <> 'removed' ORDER BY b.id DESC",
            [$uid]
        );
        $tiers = BeatController::tiersFor(array_column($rows, 'id'));
        $list = array_map(static fn ($b) => Present::beat($b, $tiers[$b['id']] ?? []) + [
            'sold'    => (int) $b['sold'],
            'revenue' => (int) $b['revenue'],
        ], $rows);
        $status = array_count_values(array_column($list, 'status'));
        $popular = $list;
        usort($popular, static fn ($a, $b) => [$b['sold'], $b['plays']] <=> [$a['sold'], $a['plays']]);

        $paid = "FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.seller_id = ? AND oi.item_type = 'license' AND o.status = 'paid'";
        $byTier = DB::all("SELECT oi.tier, COUNT(*) AS sold, SUM(oi.price - oi.commission) AS earned $paid GROUP BY oi.tier", [$uid]);
        $wallet = Analytics::wallet($uid);

        return [
            'range_days' => $days,
            'currency'   => $wallet['currency'],
            'totals'     => [
                'uploads'          => count($list),
                'live'             => (int) ($status['live'] ?? 0),
                'in_review'        => (int) (($status['in_review'] ?? 0) + ($status['processing'] ?? 0)),
                'rejected'         => (int) ($status['rejected'] ?? 0),
                'sold_exclusive'   => (int) ($status['sold_exclusive'] ?? 0),
                'plays'            => (int) array_sum(array_column($list, 'plays')),
                'licences_sold'    => (int) array_sum(array_column($byTier, 'sold')),
                'licences_range'   => (int) DB::value("SELECT COUNT(*) $paid AND o.paid_at >= ?", [$uid, $since]),
                'revenue'          => (int) array_sum(array_column($byTier, 'earned')),
                'revenue_range'    => (int) DB::value("SELECT COALESCE(SUM(oi.price - oi.commission), 0) $paid AND o.paid_at >= ?", [$uid, $since]),
                'balance'          => $wallet['balance'],
                'pending_earnings' => $wallet['pending_sales'],
                'pending_payouts'  => $wallet['pending_payouts'],
            ],
            'by_tier'      => array_map(static fn ($r) => ['tier' => $r['tier'], 'sold' => (int) $r['sold'], 'earned' => (int) $r['earned']], $byTier),
            'monthly'      => Analytics::monthlySales($uid, 'license'),
            'popular'      => array_slice($popular, 0, 5),
            'beats'        => $list,
            'recent_sales' => array_map(static fn ($r) => [
                'id' => (int) $r['id'], 'beat_id' => (int) $r['beat_id'], 'title' => $r['title'], 'tier' => $r['tier'],
                'price' => (int) $r['price'], 'earned' => (int) $r['earned'], 'buyer' => $r['buyer'], 'paid_at' => $r['paid_at'],
            ], DB::all(
                "SELECT oi.id, oi.beat_id, oi.title, oi.tier, oi.price, oi.price - oi.commission AS earned, u.name AS buyer, o.paid_at
                   FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN users u ON u.id = o.buyer_id
                  WHERE oi.seller_id = ? AND oi.item_type = 'license' AND o.status = 'paid'
                  ORDER BY o.paid_at DESC LIMIT 10",
                [$uid]
            )),
            'transactions' => Analytics::transactions($uid, 8),
        ];
    }

    /** GET /api/dashboard/studio?range=30 — requests, schedule, revenue and payments for a studio owner. */
    public function studioDashboard(Request $req): array
    {
        $uid = $req->userId();
        [$days, $since] = $this->range($req);
        $base = 'FROM bookings b JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id WHERE s.owner_id = ?';
        $ids = static fn (string $where, array $params, int $limit = 50) => array_map(
            static fn ($id) => BookingService::present((int) $id),
            array_column(DB::all("SELECT b.id $base AND $where LIMIT $limit", array_merge([$uid], $params)), 'id')
        );
        $byStatus = array_column(DB::all("SELECT b.status, COUNT(*) AS n $base AND b.status NOT IN ('held','expired') GROUP BY b.status", [$uid]), 'n', 'status');
        $releasedRange = (int) DB::value(
            "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'user' AND user_id = ? AND type = 'booking_release' AND created_at >= ?",
            [$uid, $since]
        );
        $daily = array_column(DB::all(
            "SELECT DATE(b.created_at) AS d, COUNT(*) AS n $base AND b.status NOT IN ('held','expired') AND b.created_at >= ? GROUP BY DATE(b.created_at)",
            [$uid, $since]
        ), 'n', 'd');
        $wallet = Analytics::wallet($uid);

        return [
            'range_days' => $days,
            'currency'   => $wallet['currency'],
            'studios'    => array_map(static fn ($s) => [
                'id' => (int) $s['id'], 'name' => $s['name'], 'slug' => $s['slug'], 'city' => $s['city'], 'status' => $s['status'],
                'review_note' => $s['review_note'], 'rooms' => (int) $s['rooms'], 'active_rooms' => (int) $s['active_rooms'],
                'cover_url' => upload_url($s['cover_path']),
            ], DB::all(
                "SELECT s.*, (SELECT COUNT(*) FROM studio_rooms r WHERE r.studio_id = s.id) AS rooms,
                        (SELECT COUNT(*) FROM studio_rooms r WHERE r.studio_id = s.id AND r.status = 'active') AS active_rooms
                   FROM studios s WHERE s.owner_id = ? ORDER BY s.id",
                [$uid]
            )),
            'totals'     => [
                'requests'         => (int) ($byStatus['pending'] ?? 0),
                'upcoming'         => (int) DB::value("SELECT COUNT(*) $base AND b.status = 'confirmed' AND b.start_at >= NOW()", [$uid]),
                'completed'        => (int) ($byStatus['completed'] ?? 0),
                'cancelled'        => (int) (($byStatus['cancelled'] ?? 0) + ($byStatus['rejected'] ?? 0) + ($byStatus['no_show'] ?? 0)),
                'bookings_range'   => (int) array_sum($daily),
                'hours_range'      => (int) DB::value("SELECT COALESCE(SUM(b.hours), 0) $base AND b.status IN ('confirmed','completed') AND b.start_at >= ?", [$uid, $since]),
                'revenue'          => $wallet['lifetime']['bookings'],
                'revenue_range'    => $releasedRange,
                // Deposits and balances paid by artists, held until the session is completed.
                'in_escrow'        => (int) DB::value(
                    "SELECT COALESCE(SUM(le.amount), 0) FROM ledger_entries le
                       JOIN bookings b ON le.ref_type = 'booking' AND le.ref_id = b.id
                       JOIN studio_rooms r ON r.id = b.room_id JOIN studios s ON s.id = r.studio_id
                      WHERE le.account = 'escrow' AND s.owner_id = ?",
                    [$uid]
                ),
                'balance_due_on_site' => (int) DB::value("SELECT COALESCE(SUM(b.total - b.amount_paid), 0) $base AND b.status = 'confirmed'", [$uid]),
                'balance'          => $wallet['balance'],
                'pending_payouts'  => $wallet['pending_payouts'],
                'withdrawn'        => $wallet['withdrawn'],
            ],
            'by_status'  => array_map(static fn ($k, $v) => ['status' => $k, 'count' => (int) $v], array_keys($byStatus), $byStatus),
            'daily_bookings' => Analytics::series(Analytics::days($days), $daily, 'bookings'),
            'monthly'    => array_map(static fn ($m) => ['key' => $m['key'], 'label' => $m['label'], 'total' => $m['bookings']], $wallet['monthly']),
            'requests'   => $ids("b.status = 'pending' ORDER BY b.start_at", []),
            'schedule'   => $ids("b.status = 'confirmed' AND b.end_at >= NOW() ORDER BY b.start_at", [], 30),
            'to_close'   => $ids("b.status = 'confirmed' AND b.start_at < NOW() ORDER BY b.start_at", [], 30),
            'recent'     => $ids("b.status NOT IN ('held','expired') ORDER BY b.updated_at DESC", [], 10),
            'transactions' => Analytics::transactions($uid, 8),
        ];
    }

    /** GET /api/dashboard/manager?range=30 — the artists and choirs this manager looks after. */
    public function manager(Request $req): array
    {
        $uid = $req->userId();
        [$days, $since] = $this->range($req);
        $artists = DB::all(
            "SELECT cp.*,
                    (SELECT COUNT(*) FROM tracks t WHERE t.creator_id = cp.user_id AND t.status = 'live') AS live_songs,
                    (SELECT COUNT(*) FROM tracks t WHERE t.creator_id = cp.user_id AND t.status IN ('in_review','processing')) AS in_review,
                    (SELECT COUNT(*) FROM tracks t WHERE t.creator_id = cp.user_id AND t.status = 'rejected') AS rejected,
                    (SELECT COALESCE(SUM(t.play_count), 0) FROM tracks t WHERE t.creator_id = cp.user_id) AS streams,
                    (SELECT COUNT(*) FROM play_events p JOIN tracks t ON t.id = p.track_id
                      WHERE t.creator_id = cp.user_id AND p.counted = 1 AND p.created_at >= ?) AS streams_range,
                    (SELECT COALESCE(SUM(oi.price - oi.commission), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.seller_id = cp.user_id AND o.status = 'paid' AND o.paid_at >= ?) AS earned_range,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = cp.user_id) AS followers
               FROM manager_artists ma JOIN creator_profiles cp ON cp.user_id = ma.artist_id
              WHERE ma.manager_id = ? ORDER BY cp.stage_name",
            [$since, $since, $uid]
        );
        $list = array_map(static fn ($a) => Present::creator($a) + [
            'live_songs'    => (int) $a['live_songs'],
            'in_review'     => (int) $a['in_review'],
            'rejected'      => (int) $a['rejected'],
            'streams'       => (int) $a['streams'],
            'streams_range' => (int) $a['streams_range'],
            'earned_range'  => (int) $a['earned_range'],
            'followers'     => (int) $a['followers'],
        ], $artists);
        $ids = array_column($list, 'id');
        $releases = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $releases = array_map([Present::class, 'track'], DB::all(
                "SELECT t.*, cp.stage_name AS creator_name, cp.slug AS creator_slug FROM tracks t
                   JOIN creator_profiles cp ON cp.user_id = t.creator_id
                  WHERE t.creator_id IN ($in) AND t.status <> 'removed' ORDER BY t.id DESC LIMIT 10",
                $ids
            ));
        }
        return [
            'range_days' => $days,
            'currency'   => (string) config('money.currency', 'GHS'),
            'totals'     => [
                'artists'       => count($list),
                'live_songs'    => (int) array_sum(array_column($list, 'live_songs')),
                'in_review'     => (int) array_sum(array_column($list, 'in_review')),
                'streams_range' => (int) array_sum(array_column($list, 'streams_range')),
                'earned_range'  => (int) array_sum(array_column($list, 'earned_range')),
            ],
            'artists'  => $list,
            'releases' => $releases,
        ];
    }

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

        if (Auth::hasRole($req->user, 'artist', 'choir')) {
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
                  WHERE s.owner_id = ? AND b.status IN ('pending','confirmed') AND b.start_at >= NOW() ORDER BY b.start_at LIMIT 10",
                [$uid]
            ),
        ];
    }
}
