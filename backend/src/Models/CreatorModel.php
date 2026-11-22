<?php
declare(strict_types=1);

namespace App\Models;

final class CreatorModel extends Model
{
    public function findByUserId(int $userId): ?array
    {
        return $this->one('SELECT * FROM creator_profiles WHERE user_id = ?', [$userId]);
    }

    public function findPublicProfile(string $identifier): ?array
    {
        if (ctype_digit($identifier)) {
            return $this->findByUserId((int) $identifier);
        }

        return $this->one('SELECT * FROM creator_profiles WHERE slug = ?', [$identifier]);
    }

    public function saveProfile(int $userId, array $row, ?array $existing): array
    {
        $this->transaction(function () use ($userId, $row, $existing): void {
            if ($existing) {
                if ($row) {
                    $this->update('creator_profiles', $row, 'user_id = ?', [$userId]);
                }
            } else {
                $this->insert('creator_profiles', $row + ['user_id' => $userId]);
            }

            $type = $row['type'] ?? $existing['type'];
            foreach (self::rolesFor($type) as $role) {
                (new UserModel())->addRole($userId, $role);
            }
        });

        return $this->findByUserId($userId)
            ?? throw new \RuntimeException('Creator profile could not be loaded after saving.');
    }

    /** Account role(s) that go with a profile type. */
    public static function rolesFor(string $type): array
    {
        return match ($type) {
            'both'        => ['artist', 'producer'],
            'artist', 'producer', 'choir', 'manager', 'vocal_coach' => [$type],
            default       => [],
        };
    }

    public function exists(int $userId): bool
    {
        return (bool) $this->value('SELECT 1 FROM creator_profiles WHERE user_id = ?', [$userId]);
    }

    public function updateAvatar(int $userId, string $path): void
    {
        $this->update('creator_profiles', ['avatar_path' => $path], 'user_id = ?', [$userId]);
    }

    public function follows(int $followerId, int $creatorId): bool
    {
        return (bool) $this->value(
            'SELECT 1 FROM follows WHERE follower_id = ? AND creator_id = ?',
            [$followerId, $creatorId]
        );
    }

    public function followerCount(int $creatorId): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM follows WHERE creator_id = ?', [$creatorId]);
    }

    public function totalPlays(int $creatorId): int
    {
        return (int) $this->value(
            "SELECT COALESCE(SUM(play_count), 0) FROM tracks WHERE creator_id = ? AND status = 'live'",
            [$creatorId]
        );
    }

    public function follow(int $followerId, int $creatorId): void
    {
        $this->run(
            'INSERT IGNORE INTO follows (follower_id, creator_id) VALUES (?, ?)',
            [$followerId, $creatorId]
        );
    }

    public function unfollow(int $followerId, int $creatorId): void
    {
        $this->run(
            'DELETE FROM follows WHERE follower_id = ? AND creator_id = ?',
            [$followerId, $creatorId]
        );
    }

    public function uniqueSlug(string $name, int $userId): string
    {
        $base = slugify($name);
        $slug = $base;
        $number = 2;
        while ($this->value(
            'SELECT 1 FROM creator_profiles WHERE slug = ? AND user_id <> ?',
            [$slug, $userId]
        )) {
            $slug = $base . '-' . $number++;
        }

        return $slug;
    }
}
