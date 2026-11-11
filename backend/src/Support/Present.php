<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\LicensePdf;

/**
 * Turns database rows into the JSON shapes the frontend receives.
 * Keeping this in one place means private columns (file paths, password
 * hashes) never leak into API responses by accident.
 */
final class Present
{
    public static function user(array $u, array $roles = []): array
    {
        return [
            'id'         => (int) $u['id'],
            'name'       => $u['name'],
            'phone'      => $u['phone'],
            'email'      => $u['email'],
            'country'    => $u['country'] ?? 'RW',
            'language'   => $u['language'] ?? 'en',
            'roles'      => $roles ?: ($u['roles'] ?? []),
            'created_at' => $u['created_at'] ?? null,
        ];
    }

    public static function creator(array $c): array
    {
        return [
            'id'         => (int) $c['user_id'],
            'stage_name' => $c['stage_name'],
            'slug'       => $c['slug'],
            'type'       => $c['type'],
            'bio'        => $c['bio'] ?? null,
            'city'       => $c['city'] ?? null,
            'verified'   => (bool) ($c['verified'] ?? false),
            'avatar_url' => upload_url($c['avatar_path'] ?? null),
        ];
    }

    public static function beat(array $b, array $tiers = []): array
    {
        $prices = array_map(static fn ($t) => (int) $t['price'], array_filter($tiers, static fn ($t) => (bool) $t['active']));
        return [
            'id'          => (int) $b['id'],
            'title'       => $b['title'],
            'bpm'         => $b['bpm'] !== null ? (int) $b['bpm'] : null,
            'key'         => $b['musical_key'],
            'genre'       => $b['genre'],
            'mood'        => $b['mood'],
            'tags'        => $b['tags'] ? array_values(array_filter(array_map('trim', explode(',', $b['tags'])))) : [],
            'status'      => $b['status'],
            'plays'       => (int) ($b['play_count'] ?? 0),
            'cover_url'   => upload_url($b['cover_path']),
            'preview_url' => $b['preview_path'] ? url('/api/beats/' . $b['id'] . '/preview') : null,
            'has_wav'     => (bool) $b['wav_path'],
            'has_stems'   => (bool) $b['stems_path'],
            'price_from'  => $prices ? min($prices) : null,
            'producer'    => [
                'id'         => (int) $b['producer_id'],
                'stage_name' => $b['producer_name'] ?? null,
                'slug'       => $b['producer_slug'] ?? null,
            ],
            'licenses'    => array_values(array_map([self::class, 'tier'], $tiers)),
            'created_at'  => $b['created_at'],
        ];
    }

    public static function tier(array $t): array
    {
        $cap = $t['stream_cap'] ?? LicensePdf::DEFAULT_STREAM_CAPS[$t['tier']] ?? null;
        return [
            'id'         => (int) $t['id'],
            'tier'       => $t['tier'],
            'name'       => LicensePdf::TIER_NAMES[$t['tier']] ?? $t['tier'],
            'price'      => (int) $t['price'],
            'stream_cap' => $cap !== null ? (int) $cap : null,
            'files'      => match ($t['tier']) {
                'mp3_lease' => ['mp3'],
                'wav_lease', 'unlimited' => ['mp3', 'wav'],
                'exclusive' => ['mp3', 'wav', 'stems'],
            },
            'active'     => (bool) $t['active'],
        ];
    }

    public static function track(array $t): array
    {
        $live = $t['status'] === 'live';
        return [
            'id'           => (int) $t['id'],
            'title'        => $t['title'],
            'genre'        => $t['genre'],
            'mood'         => $t['mood'],
            'language'     => $t['language'],
            'duration_sec' => $t['duration_sec'] !== null ? (int) $t['duration_sec'] : null,
            'price'        => (int) $t['price'],
            'for_sale'     => (int) $t['price'] > 0,
            'status'       => $t['status'],
            'plays'        => (int) $t['play_count'],
            'likes'        => isset($t['like_count']) ? (int) $t['like_count'] : null,
            'liked'        => isset($t['liked']) ? (bool) $t['liked'] : null,
            'cover_url'    => upload_url($t['cover_path']),
            'stream_url'   => $live ? url('/api/tracks/' . $t['id'] . '/stream') : null,
            'preview_url'  => $t['preview_path'] ? url('/api/tracks/' . $t['id'] . '/preview') : null,
            'share_url'    => url('/api/tracks/' . $t['id']),
            'album_id'     => $t['album_id'] !== null ? (int) $t['album_id'] : null,
            'creator'      => [
                'id'         => (int) $t['creator_id'],
                'stage_name' => $t['creator_name'] ?? null,
                'slug'       => $t['creator_slug'] ?? null,
            ],
            'released_at'  => $t['released_at'],
            'created_at'   => $t['created_at'],
        ];
    }

    public static function studio(array $s, array $rooms = [], ?array $rating = null): array
    {
        return [
            'id'           => (int) $s['id'],
            'name'         => $s['name'],
            'slug'         => $s['slug'],
            'description'  => $s['description'],
            'city'         => $s['city'],
            'address'      => $s['address'],
            'location'     => $s['latitude'] !== null ? ['lat' => (float) $s['latitude'], 'lng' => (float) $s['longitude']] : null,
            'phone'        => $s['phone'],
            'equipment'    => $s['equipment'],
            'amenities'    => $s['amenities'],
            'backup_power' => (bool) $s['backup_power'],
            'cover_url'    => upload_url($s['cover_path']),
            'status'       => $s['status'],
            'owner_id'     => (int) $s['owner_id'],
            'price_from'   => isset($s['price_from']) && $s['price_from'] !== null ? (int) $s['price_from'] : null,
            'rating'       => $rating ?? (isset($s['avg_rating']) ? ['average' => $s['avg_rating'] !== null ? round((float) $s['avg_rating'], 1) : null, 'count' => (int) ($s['review_count'] ?? 0)] : null),
            'rooms'        => array_map([self::class, 'room'], $rooms),
        ];
    }

    public static function room(array $r): array
    {
        return [
            'id'                => (int) $r['id'],
            'studio_id'         => (int) $r['studio_id'],
            'name'              => $r['name'],
            'description'       => $r['description'],
            'hourly_rate'       => (int) $r['hourly_rate'],
            'min_hours'         => (int) $r['min_hours'],
            'engineer_included' => (bool) $r['engineer_included'],
            'photo_url'         => upload_url($r['photo_path']),
            'status'            => $r['status'],
            'hours'             => $r['hours'] ?? null,
        ];
    }

    public static function booking(array $b, ?array $payment, int $escrow): array
    {
        return [
            'id'          => (int) $b['id'],
            'status'      => $b['status'],
            'start_at'    => $b['start_at'],
            'end_at'      => $b['end_at'],
            'hours'       => (int) $b['hours'],
            'hourly_rate' => (int) $b['hourly_rate'],
            'total'       => (int) $b['total'],
            'deposit'     => (int) $b['deposit'],
            'amount_paid' => (int) $b['amount_paid'],
            'balance_due' => max(0, (int) $b['total'] - (int) $b['amount_paid']),
            'in_escrow'   => $escrow,
            'cancelled_by'=> $b['cancelled_by'],
            'hold_expires_at' => $b['hold_expires_at'],
            'notes'       => $b['notes'],
            'room'        => ['id' => (int) $b['room_id'], 'name' => $b['room_name']],
            'studio'      => ['id' => (int) $b['studio_id'], 'name' => $b['studio_name'], 'slug' => $b['studio_slug'], 'address' => $b['studio_address']],
            'artist'      => ['id' => (int) $b['artist_id'], 'name' => $b['artist_name']],
            'payment'     => $payment ? self::payment($payment) : null,
            'created_at'  => $b['created_at'],
        ];
    }

    public static function order(array $o, array $items, ?array $payment): array
    {
        return [
            'id'       => (int) $o['id'],
            'status'   => $o['status'],
            'total'    => (int) $o['total'],
            'currency' => $o['currency'],
            'phone'    => $o['phone'],
            'provider' => $o['provider'],
            'paid_at'  => $o['paid_at'],
            'items'    => array_map(static fn ($i) => [
                'id'              => (int) $i['id'],
                'type'            => $i['item_type'],
                'title'           => $i['title'],
                'price'           => (int) $i['price'],
                'tier'            => $i['tier'],
                'beat_id'         => $i['beat_id'] !== null ? (int) $i['beat_id'] : null,
                'track_id'        => $i['track_id'] !== null ? (int) $i['track_id'] : null,
                'license_tier_id' => $i['license_tier_id'] !== null ? (int) $i['license_tier_id'] : null,
                'seller'          => ['id' => (int) $i['seller_id'], 'stage_name' => $i['seller_name'] ?? null],
            ], $items),
            'payment'    => $payment ? self::payment($payment) : null,
            'downloads_url' => $o['status'] === 'paid' ? url('/api/orders/' . $o['id'] . '/downloads') : null,
            'created_at' => $o['created_at'],
        ];
    }

    public static function payment(array $p): array
    {
        return [
            'id'             => $p['public_id'],
            'status'         => $p['status'],
            'amount'         => (int) $p['amount'],
            'currency'       => $p['currency'],
            'phone'          => $p['phone'],
            'provider'       => $p['provider'],
            'failure_reason' => $p['failure_reason'],
            // Tell the frontend what to show while waiting.
            'message'        => match ($p['status']) {
                'initiated', 'accepted' => 'Check your phone and enter your mobile money PIN to approve the payment.',
                'completed'             => 'Payment received.',
                'failed'                => 'Payment failed: ' . ($p['failure_reason'] ?? 'not approved'),
            },
        ];
    }

    public static function payout(array $p): array
    {
        return [
            'id'             => (int) $p['id'],
            'amount'         => (int) $p['amount'],
            'currency'       => $p['currency'],
            'phone'          => $p['phone'],
            'provider'       => $p['provider'],
            'status'         => $p['status'],
            'failure_reason' => $p['failure_reason'],
            'created_at'     => $p['created_at'],
            'updated_at'     => $p['updated_at'],
        ];
    }
}
