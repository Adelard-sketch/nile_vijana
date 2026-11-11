<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** POST /api/reports — flag a copyright problem, abuse or fraud for the admin team. */
final class ReportController extends Controller
{
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'target_type' => 'required|in:track,beat,studio,user',
            'target_id'   => 'required|int',
            'reason'      => 'required|in:copyright,abuse,fraud,other',
            'details'     => 'nullable|string|max:2000',
        ]);
        $table = ['track' => 'tracks', 'beat' => 'beats', 'studio' => 'studios', 'user' => 'users'][$d['target_type']];
        if (!DB::value("SELECT 1 FROM $table WHERE id = ?", [$d['target_id']])) {
            throw HttpException::notFound(ucfirst($d['target_type']));
        }
        $open = DB::value(
            "SELECT id FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open'",
            [$req->userId(), $d['target_type'], $d['target_id']]
        );
        if ($open) {
            return Response::json(['id' => (int) $open, 'message' => 'You already reported this. Our team is looking at it.']);
        }
        $id = DB::insert('reports', [
            'reporter_id' => $req->userId(),
            'target_type' => $d['target_type'],
            'target_id'   => $d['target_id'],
            'reason'      => $d['reason'],
            'details'     => $d['details'] ?? null,
        ]);
        return Response::created(['id' => $id, 'message' => 'Thank you. Our team will review this report.']);
    }
}
