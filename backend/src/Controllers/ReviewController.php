<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Reviews for studios, beats and songs.
 * Studios: only artists with a completed session there. Beats: only buyers of a licence.
 */
final class ReviewController extends Controller
{
    /** GET /api/reviews?target_type=studio&target_id=1 */
    public function index(Request $req): array
    {
        $d = Validator::validate($req->query, [
            'target_type' => 'required|in:studio,beat,track',
            'target_id'   => 'required|int',
        ]);
        [$page, $per, $offset] = pagination($req->query);
        $summary = DB::one(
            'SELECT AVG(rating) AS avg, COUNT(*) AS n FROM reviews WHERE target_type = ? AND target_id = ?',
            [$d['target_type'], $d['target_id']]
        );
        $rows = DB::all(
            "SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.name AS author
               FROM reviews rv JOIN users u ON u.id = rv.user_id
              WHERE rv.target_type = ? AND rv.target_id = ? ORDER BY rv.id DESC LIMIT $per OFFSET $offset",
            [$d['target_type'], $d['target_id']]
        );
        return $this->paginated($rows, $page, $per, (int) $summary['n']) + [
            'average' => $summary['avg'] !== null ? round((float) $summary['avg'], 1) : null,
        ];
    }

    /** POST /api/reviews {"target_type":"studio","target_id":1,"rating":5,"comment":"Great engineer"} */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'target_type' => 'required|in:studio,beat,track',
            'target_id'   => 'required|int',
            'rating'      => 'required|int|min:1|max:5',
            'comment'     => 'nullable|string|max:1000',
        ]);
        $uid = $req->userId();
        $id = $d['target_id'];

        $allowed = match ($d['target_type']) {
            'studio' => DB::value(
                "SELECT 1 FROM bookings b JOIN studio_rooms r ON r.id = b.room_id
                  WHERE r.studio_id = ? AND b.artist_id = ? AND b.status = 'completed' LIMIT 1",
                [$id, $uid]
            ),
            'beat' => DB::value(
                "SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                  WHERE oi.beat_id = ? AND o.buyer_id = ? AND o.status = 'paid' LIMIT 1",
                [$id, $uid]
            ),
            'track' => DB::value("SELECT 1 FROM tracks WHERE id = ? AND status = 'live' AND creator_id <> ?", [$id, $uid]),
        };
        if (!$allowed) {
            throw HttpException::forbidden(match ($d['target_type']) {
                'studio' => 'You can review a studio after a completed session there',
                'beat'   => 'You can review a beat after buying a licence',
                'track'  => 'This song cannot be reviewed',
            });
        }

        DB::run(
            'INSERT INTO reviews (user_id, target_type, target_id, rating, comment) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)',
            [$uid, $d['target_type'], $id, $d['rating'], $d['comment'] ?? null]
        );
        return Response::created(['saved' => true]);
    }
}
