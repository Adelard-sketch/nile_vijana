<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Append-only money ledger.
 *
 * Every money movement is written as one or more rows and never edited.
 * A balance is always SUM(amount), so it can be audited and recomputed.
 * Each movement balances to zero across accounts, for example a 10,000 RWF beat sale:
 *     user (producer)  +8,500   sale
 *     platform         +1,500   commission
 * (the 10,000 itself arrived from mobile money into the platform's pawaPay wallet)
 */
final class Ledger
{
    public static function post(string $account, ?int $userId, int $amount, string $type, string $refType, int $refId, string $memo = ''): void
    {
        if ($amount === 0) {
            return;
        }
        DB::insert('ledger_entries', [
            'account'  => $account,
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => (string) config('money.currency', 'RWF'),
            'type'     => $type,
            'ref_type' => $refType,
            'ref_id'   => $refId,
            'memo'     => mb_substr($memo, 0, 255),
        ]);
    }

    public static function balance(int $userId): int
    {
        return (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'user' AND user_id = ?", [$userId]);
    }

    public static function platformBalance(): int
    {
        return (int) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'platform'");
    }

    /** Money held in escrow for one booking. */
    public static function escrowFor(int $bookingId): int
    {
        return (int) DB::value(
            "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries WHERE account = 'escrow' AND ref_type = 'booking' AND ref_id = ?",
            [$bookingId]
        );
    }

    /** Recent wallet movements for a user. */
    public static function history(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return DB::all(
            "SELECT id, amount, type, ref_type, ref_id, memo, created_at
               FROM ledger_entries WHERE account = 'user' AND user_id = ?
              ORDER BY id DESC LIMIT $limit",
            [$userId]
        );
    }
}
