<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Payments\PaymentService;

/**
 * Withdrawals from a wallet to mobile money.
 * requested -> (admin approves) -> processing -> completed | failed
 *           -> (admin rejects)  -> rejected
 * The money leaves the wallet when the request is made (so it can't be spent twice)
 * and comes back automatically if the payout fails or is rejected.
 */
final class PayoutService
{
    public static function request(array $user, int $amount, ?string $phone, ?string $provider): array
    {
        $uid = (int) $user['id'];
        $min = (int) config('money.min_payout', 1000);
        if ($amount < $min) {
            throw HttpException::invalid("The minimum withdrawal is $min RWF", ['amount' => 'Too small']);
        }

        $profile = DB::one('SELECT payout_phone, payout_provider FROM creator_profiles WHERE user_id = ?', [$uid]);
        $phone = $phone ?: ($profile['payout_phone'] ?? null) ?: ($user['phone'] ?? null);
        if (!$phone) {
            throw HttpException::invalid('Add a mobile money number', ['phone' => 'Required']);
        }
        if (!$provider && $profile && $profile['payout_phone'] === $phone) {
            $provider = $profile['payout_provider'];
        }
        $provider = $provider ?: detect_provider($phone);
        if (!$provider) {
            throw HttpException::invalid('Tell us the mobile money network', ['provider' => 'e.g. MTN_MOMO_RWA or AIRTEL_RWA']);
        }

        return DB::tx(static function () use ($uid, $amount, $phone, $provider) {
            // Lock the user row: two withdrawals at the same moment can't both pass the balance check.
            DB::one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
            $balance = Ledger::balance($uid);
            if ($balance < $amount) {
                throw HttpException::invalid('Your balance is ' . number_format($balance) . ' RWF', ['amount' => 'More than your balance']);
            }
            $id = DB::insert('payouts', [
                'public_id' => uuid4(),
                'user_id'   => $uid,
                'amount'    => $amount,
                'currency'  => (string) config('money.currency', 'RWF'),
                'phone'     => $phone,
                'provider'  => $provider,
            ]);
            Ledger::post('user', $uid, -$amount, 'payout', 'payout', $id, "Withdrawal to +$phone");
            return self::find($id);
        });
    }

    public static function approve(array $admin, int $payoutId): array
    {
        $p = DB::tx(static function () use ($admin, $payoutId) {
            $p = DB::one('SELECT * FROM payouts WHERE id = ? FOR UPDATE', [$payoutId]);
            if (!$p) {
                throw HttpException::notFound('Payout');
            }
            if ($p['status'] !== 'requested') {
                throw HttpException::conflict("This payout is already {$p['status']}");
            }
            DB::update('payouts', [
                'status'      => 'processing',
                'approved_by' => $admin['id'],
                'gateway'     => PaymentService::gateway()->name(),
            ], 'id = ?', [$payoutId]);
            return self::find($payoutId);
        });

        try {
            $result = PaymentService::gateway($p['gateway'])->initiatePayout($p['public_id'], (int) $p['amount'], $p['currency'], $p['phone'], $p['provider']);
        } catch (\Throwable $e) {
            app_log('payments', "payout initiate failed {$p['public_id']}: " . $e->getMessage());
            $result = ['state' => 'unknown'];
        }
        if ($result['state'] === 'rejected') {
            self::fail($payoutId, (string) ($result['message'] ?? 'Rejected by provider'));
        }
        return self::find($payoutId);
    }

    public static function reject(int $payoutId, string $reason): array
    {
        $p = self::find($payoutId);
        if ($p['status'] !== 'requested') {
            throw HttpException::conflict("This payout is already {$p['status']}");
        }
        self::fail($payoutId, $reason, 'rejected');
        return self::find($payoutId);
    }

    public static function refresh(array $p, bool $force = false): array
    {
        if ($p['status'] !== 'processing') {
            return $p;
        }
        if (!$force && $p['last_checked_at'] && strtotime($p['last_checked_at']) > time() - 3) {
            return $p;
        }
        $id = (int) $p['id'];
        DB::run('UPDATE payouts SET last_checked_at = NOW(), updated_at = updated_at WHERE id = ?', [$id]);
        try {
            $s = PaymentService::gateway($p['gateway'])->payoutStatus($p['public_id']);
        } catch (\Throwable $e) {
            app_log('payments', "payout status failed {$p['public_id']}: " . $e->getMessage());
            return self::find($id);
        }

        if ($s['state'] === 'completed') {
            $changed = DB::update('payouts', ['status' => 'completed', 'provider_txn_id' => $s['provider_txn_id'] ?? null], "id = ? AND status = 'processing'", [$id]);
            if ($changed) {
                $user = DB::one('SELECT phone FROM users WHERE id = ?', [$p['user_id']]);
                if ($user && $user['phone']) {
                    Sms::send($user['phone'], 'Nile Vijana: ' . number_format((int) $p['amount']) . ' RWF has been sent to +' . $p['phone'] . '.');
                }
            }
        } elseif ($s['state'] === 'failed') {
            self::fail($id, (string) ($s['message'] ?? 'Payout failed'));
        } elseif ($s['state'] === 'not_found' && strtotime($p['updated_at']) < time() - 120) {
            self::fail($id, 'The payout never reached the provider');
        }
        return self::find($id);
    }

    /** Mark failed/rejected and put the money back in the wallet (exactly once). */
    public static function fail(int $payoutId, string $reason, string $status = 'failed'): void
    {
        DB::tx(static function () use ($payoutId, $reason, $status) {
            $p = DB::one('SELECT * FROM payouts WHERE id = ? FOR UPDATE', [$payoutId]);
            if (!$p || !in_array($p['status'], ['requested', 'processing'], true)) {
                return;
            }
            DB::update('payouts', ['status' => $status, 'failure_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$payoutId]);
            Ledger::post('user', (int) $p['user_id'], (int) $p['amount'], 'payout_reversal', 'payout', $payoutId, "Payout $status: $reason");
        });
    }

    public static function find(int $id): array
    {
        $p = DB::one('SELECT * FROM payouts WHERE id = ?', [$id]);
        if (!$p) {
            throw HttpException::notFound('Payout');
        }
        return $p;
    }
}
