<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * The approval workflow for songs and beats, enforced on the server.
 *
 *   creator uploads  -> in_review (never public)    -> admin approves -> live (public)
 *                                                    -> admin rejects -> rejected (+ reason)
 *   rejected         -> creator fixes and resubmits -> in_review again
 *   admin uploads    -> admin chooses: live now, or in_review
 *
 * Public endpoints only ever return status = 'live', so pending or rejected
 * content cannot be listed, streamed or bought, whatever the frontend does.
 * (The old moderation.auto_approve setting no longer applies to creators.)
 */
final class Moderation
{
    public const TABLES = ['tracks' => 'Song', 'beats' => 'Beat'];

    /**
     * Status for a new or resubmitted upload.
     * Returns [status now, status after audio processing, extra columns].
     */
    public static function submission(?array $user, ?string $publish, bool $needsProcessing): array
    {
        $isAdmin = Auth::isAdmin($user);
        $target = $isAdmin ? ($publish === 'in_review' ? 'in_review' : 'live') : 'in_review';
        $columns = [
            'submitted_at' => now(),
            'review_note'  => null,
            'reviewed_by'  => null,
            'reviewed_at'  => null,
        ];
        if ($isAdmin && $target === 'live') {
            $columns['reviewed_by'] = (int) $user['id'];
            $columns['reviewed_at'] = now();
        }
        return [$needsProcessing ? 'processing' : $target, $target, $columns];
    }

    /**
     * Admin decision on a song or beat.
     * approve -> live; reject -> rejected with a reason the creator can read.
     */
    public static function review(string $table, int $id, string $decision, ?string $note, int $adminId): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException('Unknown content table');
        }
        $label = self::TABLES[$table];
        $ownerCol = $table === 'tracks' ? 'creator_id' : 'producer_id';
        $row = DB::one("SELECT x.*, u.phone FROM $table x JOIN users u ON u.id = x.$ownerCol WHERE x.id = ?", [$id])
            ?? throw HttpException::notFound($label);

        if ($row['status'] === 'sold_exclusive') {
            throw HttpException::conflict('This beat was sold exclusively and can no longer be changed');
        }
        if ($row['status'] === 'processing') {
            throw HttpException::conflict('The audio is still being processed. Try again in a minute.');
        }
        $note = trim((string) $note);
        if ($decision === 'reject' && mb_strlen($note) < 5) {
            throw HttpException::invalid('Tell the creator why it was rejected', ['note' => 'Write a short reason (at least 5 characters)']);
        }

        $live = $decision === 'approve';
        $changes = [
            'status'      => $live ? 'live' : 'rejected',
            'review_note' => $live ? null : mb_substr($note, 0, 500),
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
        ];
        if ($table === 'tracks' && $live && !$row['released_at']) {
            $changes['released_at'] = now();
        }
        DB::update($table, $changes, 'id = ?', [$id]);
        if ($table === 'beats' && $live && $row['status'] === 'removed') {
            // A takedown closes every licence; approving the beat again re-opens them.
            DB::update('license_tiers', ['active' => 1], 'beat_id = ?', [$id]);
        }

        if ($row['phone']) {
            $what = strtolower($label) . ' "' . $row['title'] . '"';
            Sms::send($row['phone'], $live
                ? "Nile Vijana: your $what is approved and now public."
                : "Nile Vijana: your $what needs changes: $note");
        }
        return ['id' => $id, 'status' => $changes['status'], 'review_note' => $changes['review_note']];
    }
}
