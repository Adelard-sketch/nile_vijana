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
            'username'   => $u['username'] ?? null,
            'phone'      => $u['phone'],
            'email'      => $u['email'],
            'email_verified' => !empty($u['email_verified_at']),
            'country'    => $u['country'] ?? 'GH',
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
            'genres'     => self::csv($c['genres'] ?? null),
            'members_count'    => isset($c['members_count']) ? (int) $c['members_count'] : null,
            'experience_years' => isset($c['experience_years']) ? (int) $c['experience_years'] : null,
            'specialization'   => $c['specialization'] ?? null,
            'company'          => $c['company'] ?? null,
            'website'          => $c['website'] ?? null,
        ];
    }

    /** Creator profile plus private contact details (owner and admin views only). */
    public static function creatorPrivate(array $c): array
    {
        return self::creator($c) + [
            'contact_email'   => $c['contact_email'] ?? null,
            'contact_phone'   => $c['contact_phone'] ?? null,
            'payout_phone'    => $c['payout_phone'] ?? null,
            'payout_provider' => $c['payout_provider'] ?? null,
            'created_at'      => $c['created_at'] ?? null,
        ];
    }

    /** Review state shared by songs and beats: why it was rejected and when it was decided. */
    public static function review(array $row): array
    {
        return [
            'review_note'  => $row['review_note'] ?? null,
            'reviewed_at'  => $row['reviewed_at'] ?? null,
            'submitted_at' => $row['submitted_at'] ?? null,
        ];
    }

    public static function csv(?string $value): array
    {
        return $value ? array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen')) : [];
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
            'description' => $b['description'] ?? null,
            'release_date'=> $b['release_date'] ?? null,
            'created_at'  => $b['created_at'],
        ] + self::review($b);
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
            'saves'        => isset($t['save_count']) ? (int) $t['save_count'] : null,
            'saved'        => isset($t['saved']) ? (bool) $t['saved'] : null,
            'allow_download' => (bool) ($t['allow_download'] ?? false),
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
            'description'  => $t['description'] ?? null,
            'release_date' => $t['release_date'] ?? null,
            'created_at'   => $t['created_at'],
        ] + self::review($t);
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
            'review_note'  => $s['review_note'] ?? null,
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
            'studio_note' => $b['studio_note'] ?? null,
            'responded_at'=> $b['responded_at'] ?? null,
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
        $open = in_array($p['status'], ['initiated', 'accepted'], true);
        $hosted = ($p['gateway'] ?? '') === 'paystack';
        return [
            'id'             => $p['public_id'],
            'reference'      => $p['public_id'],
            'gateway'        => $p['gateway'] ?? null,
            'purpose'        => $p['purpose'] ?? null,
            'status'         => $p['status'],
            'amount'         => (int) $p['amount'],
            'currency'       => $p['currency'],
            'phone'          => $p['phone'],
            'provider'       => $p['provider'],
            'channel'        => $p['channel'] ?? null,
            'failure_reason' => $p['failure_reason'],
            // Only while the payment is open: what the browser needs to show the Paystack checkout.
            'checkout_url'   => $open ? ($p['checkout_url'] ?? null) : null,
            'access_code'    => $open ? ($p['access_code'] ?? null) : null,
            'completed_at'   => $p['completed_at'] ?? null,
            'created_at'     => $p['created_at'] ?? null,
            // Tell the frontend what to show while waiting.
            'message'        => match ($p['status']) {
                'initiated', 'accepted' => $hosted
                    ? 'Complete the payment on the secure Paystack checkout (card or mobile money).'
                    : 'Check your phone and approve the payment.',
                'completed'             => 'Payment received. Thank you!',
                'failed'                => 'Payment not completed: ' . ($p['failure_reason'] ?? 'it was not approved') . '. You were not charged.',
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
            'sent_note'      => $p['sent_note'] ?? null,
            'gateway'        => $p['gateway'] ?? null,
            'created_at'     => $p['created_at'],
            'updated_at'     => $p['updated_at'],
        ];
    }
}
