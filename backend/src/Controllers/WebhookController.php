<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\Payments\PaymentService;

/**
 * POST /api/webhooks/pawapay
 * Set this URL as the deposit AND payout callback URL in the pawaPay dashboard.
 * (On localhost pawaPay cannot reach you; polling and the worker still pick up results.)
 */
final class WebhookController extends Controller
{
    public function pawapay(Request $req): array
    {
        $body = $req->all();
        app_log('webhooks', 'pawaPay callback', [
            'depositId' => $body['depositId'] ?? null,
            'payoutId'  => $body['payoutId'] ?? null,
            'status'    => $body['status'] ?? null,
        ]);
        // We don't trust the body's status: handleCallback re-checks with pawaPay's API.
        return ['received' => true, 'type' => PaymentService::handleCallback($body)];
    }
}
