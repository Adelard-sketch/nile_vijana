<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Featured Spotify music on the home and Music pages.
 *
 * Spotify music plays inside Spotify's own embed player (open.spotify.com/embed/...).
 * Visitors who are not signed in to Spotify in their browser hear 30-second previews;
 * signed-in Spotify listeners can hear full songs. The website says so on every card.
 * Cover art and titles come from Spotify's public oEmbed endpoint (no credentials needed)
 * and are stored, so pages load fast and still look right if Spotify is slow.
 */
final class HomeController extends Controller
{
    private const TYPES = ['track', 'album', 'playlist'];

    /** GET /api/home/featured-spotify */
    public function featuredSpotify(Request $req): array
    {
        $rows = DB::all(
            'SELECT id, title, subtitle, embed_type, spotify_id, cover_url
               FROM featured_spotify_embeds
              WHERE is_active = 1
              ORDER BY FIELD(embed_type, "track", "album", "playlist"), display_order, id'
        );
        // Fill missing artwork from Spotify, a couple per request so a page never waits long.
        $filled = 0;
        foreach ($rows as &$row) {
            if ($row['cover_url'] === null && $filled < 2) {
                $filled++;
                $row = array_merge($row, self::fetchDetails((int) $row['id'], $row['embed_type'], $row['spotify_id'], $row['title']));
            }
        }
        unset($row);
        return ['data' => array_map([self::class, 'present'], $rows)];
    }

    /* ------------------------------------------------------------------ admin */

    /** GET /api/admin/featured-spotify */
    public function adminIndex(Request $req): array
    {
        $rows = DB::all('SELECT * FROM featured_spotify_embeds ORDER BY FIELD(embed_type, "track", "album", "playlist"), display_order, id');
        return ['data' => array_map(static fn ($r) => self::present($r) + ['is_active' => (bool) $r['is_active'], 'display_order' => (int) $r['display_order']], $rows)];
    }

    /** POST /api/admin/featured-spotify {"link": "https://open.spotify.com/track/...", "title": "optional"} */
    public function adminCreate(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'link'  => 'required|string|max:500',
            'title' => 'nullable|string|max:160',
        ], ['link' => 'Spotify link']);
        [$type, $id] = self::parseLink($d['link']);
        if (DB::value('SELECT id FROM featured_spotify_embeds WHERE embed_type = ? AND spotify_id = ?', [$type, $id])) {
            throw HttpException::invalid('This is already featured.', ['link' => 'This Spotify ' . $type . ' is already in the list.']);
        }
        $details = self::oembed($type, $id);
        $title = $d['title'] ?? ($details['title'] ?? null);
        if (!$title) {
            throw HttpException::invalid('Add a title.', ['title' => 'Spotify could not be reached to read the title. Type the title yourself.']);
        }
        $order = (int) DB::value('SELECT COALESCE(MAX(display_order), 0) + 1 FROM featured_spotify_embeds WHERE embed_type = ?', [$type]);
        $newId = DB::insert('featured_spotify_embeds', [
            'title'         => mb_substr($title, 0, 160),
            'subtitle'      => isset($details['subtitle']) ? mb_substr($details['subtitle'], 0, 160) : null,
            'embed_type'    => $type,
            'spotify_id'    => $id,
            'cover_url'     => $details['cover_url'] ?? '',
            'display_order' => min($order, 65535),
        ]);
        return Response::created(self::present(DB::one('SELECT * FROM featured_spotify_embeds WHERE id = ?', [$newId])));
    }

    /** PATCH /api/admin/featured-spotify/{id} {"title", "subtitle", "is_active", "display_order", "refresh": true} */
    public function adminUpdate(Request $req): array
    {
        $row = DB::one('SELECT * FROM featured_spotify_embeds WHERE id = ?', [$req->id()]) ?? throw HttpException::notFound('Featured item');
        $d = Validator::validate($req->all(), [
            'title'         => 'nullable|string|min:1|max:160',
            'subtitle'      => 'nullable|string|max:160',
            'is_active'     => 'nullable|bool',
            'display_order' => 'nullable|int|min:0|max:1000',
            'refresh'       => 'nullable|bool',
        ]);
        $changes = array_filter([
            'title'         => $d['title'] ?? null,
            'subtitle'      => $d['subtitle'] ?? null,
            'display_order' => $d['display_order'] ?? null,
        ], static fn ($v) => $v !== null);
        if (isset($d['is_active'])) {
            $changes['is_active'] = $d['is_active'] ? 1 : 0;
        }
        if ($changes) {
            DB::update('featured_spotify_embeds', $changes, 'id = ?', [$row['id']]);
        }
        if (!empty($d['refresh'])) {
            self::fetchDetails((int) $row['id'], $row['embed_type'], $row['spotify_id'], $row['title'], true);
        }
        return self::present(DB::one('SELECT * FROM featured_spotify_embeds WHERE id = ?', [$row['id']]));
    }

    /** DELETE /api/admin/featured-spotify/{id} */
    public function adminDelete(Request $req): Response
    {
        DB::run('DELETE FROM featured_spotify_embeds WHERE id = ?', [$req->id()]);
        return Response::noContent();
    }

    /* ------------------------------------------------------------------ helpers */

    public static function present(array $r): array
    {
        $type = $r['embed_type'];
        return [
            'id'          => (int) $r['id'],
            'title'       => $r['title'],
            'subtitle'    => $r['subtitle'] ?? null,
            'embed_type'  => $type,
            'spotify_id'  => $r['spotify_id'],
            'cover_url'   => ($r['cover_url'] ?? '') !== '' ? $r['cover_url'] : null,
            'spotify_url' => "https://open.spotify.com/$type/{$r['spotify_id']}",
            'embed_url'   => "https://open.spotify.com/embed/$type/{$r['spotify_id']}?utm_source=generator",
        ];
    }

    /** "https://open.spotify.com/intl-fr/track/4iJJ...?si=x" or "spotify:album:..." -> [type, id] */
    public static function parseLink(string $link): array
    {
        $link = trim($link);
        if (preg_match('~^spotify:(track|album|playlist):([A-Za-z0-9]{22})$~', $link, $m)
            || preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[A-Za-z]{2})?/)?(?:embed/)?(track|album|playlist)/([A-Za-z0-9]{22})(?:[/?#].*)?$~', $link, $m)) {
            return [$m[1], $m[2]];
        }
        throw HttpException::invalid('That is not a Spotify link.', ['link' => 'Paste a Spotify song, album or playlist link, like https://open.spotify.com/track/...']);
    }

    /** Store title/artwork from Spotify for one row; remembers a failed attempt with an empty cover. */
    private static function fetchDetails(int $rowId, string $type, string $spotifyId, string $title, bool $overwriteTitle = false): array
    {
        $details = self::oembed($type, $spotifyId);
        $changes = ['cover_url' => $details['cover_url'] ?? ''];
        $placeholder = (bool) preg_match('/^Featured (song|playlist|album) \d+$/i', $title);
        if (!empty($details['title']) && ($overwriteTitle || $placeholder)) {
            $changes['title'] = mb_substr($details['title'], 0, 160);
        }
        if (!empty($details['subtitle'])) {
            $changes['subtitle'] = mb_substr($details['subtitle'], 0, 160);
        }
        DB::update('featured_spotify_embeds', $changes, 'id = ?', [$rowId]);
        return $changes;
    }

    /** Spotify's public oEmbed endpoint: title and artwork, no account needed. */
    private static function oembed(string $type, string $spotifyId): array
    {
        $ch = curl_init('https://open.spotify.com/oembed?url=' . rawurlencode("https://open.spotify.com/$type/$spotifyId"));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = $code === 200 && is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            app_log('spotify', "oEmbed unavailable for $type/$spotifyId (HTTP $code)");
            return [];
        }
        return [
            'title'     => isset($data['title']) ? (string) $data['title'] : null,
            'subtitle'  => ucfirst($type) . ' on Spotify',
            'cover_url' => isset($data['thumbnail_url']) && str_starts_with((string) $data['thumbnail_url'], 'https://') ? (string) $data['thumbnail_url'] : null,
        ];
    }
}
