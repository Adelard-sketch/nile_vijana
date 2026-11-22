<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\PayoutService;
use App\Support\Present;

final class PayoutController extends Controller
{
    /**
     * POST /api/payouts {"amount": 200, "phone": "0241234567", "provider": "MTN"}  (GH₵; provider MTN, VOD or ATL)
     * phone/provider default to the payout number on the creator profile.
     */
    public function store(Request $req): Response
    {
        $d = Validator::validate($req->all(), [
            'amount'   => 'required|int|min:1',
            'phone'    => 'nullable|phone',
            'provider' => 'nullable|in:MTN,VOD,ATL',
        ]);
        $p = PayoutService::request($req->user, $d['amount'], $d['phone'] ?? null, $d['provider'] ?? null);
        return Response::created(Present::payout($p) + ['next_step' => 'An admin approves withdrawals, usually within one working day.']);
    }

    /** GET /api/payouts — my withdrawals (refreshes any that are being sent). */
    public function index(Request $req): array
    {
        $rows = DB::all('SELECT * FROM payouts WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$req->userId()]);
        foreach ($rows as &$p) {
            if ($p['status'] === 'processing') {
                $p = PayoutService::refresh($p);
            }
        }
        return ['data' => array_map([Present::class, 'payout'], $rows)];
    }
}
