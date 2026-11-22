<?php
declare(strict_types=1);

namespace App\Models;

final class BeatModel extends Model
{
    public function liveByProducer(int $producerId, string $producerName, string $producerSlug): array
    {
        return $this->all(
            "SELECT b.*, ? AS producer_name, ? AS producer_slug FROM beats b
              WHERE b.producer_id = ? AND b.status = 'live' ORDER BY b.created_at DESC LIMIT 100",
            [$producerName, $producerSlug, $producerId]
        );
    }

    public function tiersFor(array $beatIds): array
    {
        if (!$beatIds) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($beatIds), '?'));
        $tiers = [];
        foreach ($this->all(
            "SELECT * FROM license_tiers WHERE beat_id IN ($marks)
             ORDER BY FIELD(tier,'mp3_lease','wav_lease','unlimited','exclusive')",
            array_values($beatIds)
        ) as $tier) {
            $tiers[$tier['beat_id']][] = $tier;
        }

        return $tiers;
    }
}
