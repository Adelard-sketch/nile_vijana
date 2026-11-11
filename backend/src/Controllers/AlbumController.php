<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Storage;
use App\Support\Present;

final class AlbumController extends Controller
{
    /** POST /api/albums (multipart or JSON): title, release_date, cover (image) */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'title'        => 'required|string|max:160',
            'release_date' => 'nullable|date',
        ]);
        $cover = $req->file('cover') ? Storage::storeImage($req->file('cover'), 'covers', 'cover') : null;
        $id = DB::insert('albums', [
            'creator_id'   => $req->userId(),
            'title'        => $d['title'],
            'release_date' => $d['release_date'] ?? null,
            'cover_path'   => $cover,
        ]);
        return Response::created($this->present($id, true));
    }

    /** GET /api/albums/{id} — album with its live songs. */
    public function show(Request $req): array
    {
        return $this->present($req->id(), false);
    }

    private function present(int $id, bool $allTracks): array
    {
        $a = DB::one(
            'SELECT a.*, cp.stage_name, cp.slug FROM albums a LEFT JOIN creator_profiles cp ON cp.user_id = a.creator_id WHERE a.id = ?',
            [$id]
        ) ?? throw HttpException::notFound('Album');
        $tracks = DB::all(
            "SELECT t.*, ? AS creator_name, ? AS creator_slug FROM tracks t
              WHERE t.album_id = ? " . ($allTracks ? "AND t.status <> 'removed'" : "AND t.status = 'live'") . ' ORDER BY t.id',
            [$a['stage_name'], $a['slug'], $id]
        );
        return [
            'id'           => (int) $a['id'],
            'title'        => $a['title'],
            'release_date' => $a['release_date'],
            'cover_url'    => upload_url($a['cover_path']),
            'creator'      => ['id' => (int) $a['creator_id'], 'stage_name' => $a['stage_name'], 'slug' => $a['slug']],
            'tracks'       => array_map([Present::class, 'track'], $tracks),
        ];
    }
}
