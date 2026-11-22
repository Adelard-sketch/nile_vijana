<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Signer;
use App\Services\Storage;

/**
 * GET /api/files/{token} — paid downloads through signed links.
 * No login needed: the token itself proves who bought what, and it expires.
 */
final class FileController extends Controller
{
    public function download(Request $req): Response
    {
        $p = Signer::verify($req->param('token'));
        if (!$p) {
            throw new HttpException(410, 'This download link has expired. Open your order again to get a new one.');
        }
        $item = DB::one(
            "SELECT oi.*, o.buyer_id, b.mp3_path, b.wav_path, b.stems_path, t.original_path
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
               LEFT JOIN beats b ON b.id = oi.beat_id
               LEFT JOIN tracks t ON t.id = oi.track_id
              WHERE oi.id = ? AND o.status = 'paid'",
            [(int) $p['i']]
        );
        if (!$item || (int) $item['buyer_id'] !== (int) $p['u']) {
            throw HttpException::notFound('File');
        }

        $kind = (string) $p['k'];
        DB::insert('downloads', ['order_item_id' => $item['id'], 'user_id' => $item['buyer_id'], 'file_kind' => $kind, 'ip' => $req->ip()]);

        if ($kind === 'license') {
            [$pdf, $name] = OrderController::licensePdf((int) $item['id']);
            return Response::bytes($pdf, 'application/pdf', $name, false);
        }

        $allowed = match ($item['tier']) {
            'mp3_lease'              => ['mp3'],
            'wav_lease', 'unlimited' => ['mp3', 'wav'],
            'exclusive'              => ['mp3', 'wav', 'stems'],
            default                  => ['track'],
        };
        $path = match ($kind) {
            'mp3'   => $item['mp3_path'],
            'wav'   => $item['wav_path'],
            'stems' => $item['stems_path'],
            'track' => $item['original_path'],
            default => null,
        };
        if (!in_array($kind, $allowed, true) || !$path) {
            throw HttpException::notFound('File');
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = slugify($item['title']) . '.' . $ext;
        return Response::file(Storage::privatePath($path), Storage::mimeFor($path), $name);
    }

    /**
     * A short-lived link that lets the owner or an admin listen to a song or beat
     * that is not public yet (waiting for review or rejected). Built by the API
     * only for those people, so the audio never becomes public before approval.
     */
    public static function listenUrl(string $type, int $id, int $ttl = 7200): string
    {
        return url('/api/media/' . Signer::sign(['r' => 'listen', 't' => $type, 'id' => $id], $ttl));
    }

    /** Short-lived link that downloads a song's full-quality file (see TrackController::download). */
    public static function downloadUrl(int $trackId, int $ttl = 600): string
    {
        return url('/api/media/' . Signer::sign(['r' => 'download', 't' => 'track', 'id' => $trackId], $ttl));
    }

    /** GET /api/media/{token} — streams the audio behind a listenUrl() link (supports seeking), or downloads a song. */
    public function listen(Request $req): Response
    {
        $p = Signer::verify($req->param('token'));
        if ($p && ($p['r'] ?? '') === 'download' && ($p['t'] ?? '') === 'track') {
            $t = DB::one('SELECT title, original_path FROM tracks WHERE id = ?', [(int) $p['id']]) ?? throw HttpException::notFound('Song');
            $path = (string) $t['original_path'];
            return Response::file(Storage::privatePath($path), Storage::mimeFor($path), slugify($t['title']) . '.' . pathinfo($path, PATHINFO_EXTENSION));
        }
        if (!$p || ($p['r'] ?? '') !== 'listen' || !in_array($p['t'] ?? '', ['track', 'beat'], true)) {
            throw new HttpException(410, 'This link has expired. Reload the page to get a new one.');
        }
        $path = $p['t'] === 'track'
            ? DB::value('SELECT COALESCE(stream_path, original_path) FROM tracks WHERE id = ?', [(int) $p['id']])
            : DB::value('SELECT COALESCE(preview_path, mp3_path) FROM beats WHERE id = ?', [(int) $p['id']]);
        if (!$path) {
            throw HttpException::notFound('Audio');
        }
        return Response::file(Storage::privatePath((string) $path), Storage::mimeFor((string) $path));
    }
}
