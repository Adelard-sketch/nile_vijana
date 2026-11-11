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
}
