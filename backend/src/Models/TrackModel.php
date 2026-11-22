<?php
declare(strict_types=1);

namespace App\Models;

final class TrackModel extends Model
{
    public function liveByCreator(int $creatorId, string $creatorName, string $creatorSlug): array
    {
        return $this->all(
            "SELECT t.*, ? AS creator_name, ? AS creator_slug FROM tracks t
              WHERE t.creator_id = ? AND t.status = 'live' ORDER BY t.released_at DESC LIMIT 100",
            [$creatorName, $creatorSlug, $creatorId]
        );
    }
}
