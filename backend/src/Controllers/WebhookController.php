<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\PaymentService;

/**
 * POST /api/webhooks/paystack
 * Set https://<your-domain>/nilevijanacreatives/backend/public/api/webhooks/paystack as the
 * Webhook URL in Paystack (Settings > API Keys & Webhooks). Each call is signed with
 * HMAC-SHA512 of the body using the secret key (x-paystack-signature). Unsigned or wrongly
 * signed calls are refused, and even a valid one only makes us re-verify with Paystack.
 *
 * POST /api/webhooks/pawapay
 * Set this URL as the deposit AND payout callback URL in the pawaPay dashboard.
 * (On localhost pawaPay cannot reach you; polling and the worker still pick up results.)
 */
final class WebhookController extends Controller
{
    public function paystack(Request $req): array
    {
        $raw = (string) file_get_contents('php://input');
        if (!PaystackGateway::validSignature($raw, $req->header('X-Paystack-Signature'))) {
            app_log('webhooks', 'paystack: bad signature', ['ip' => $req->ip()]);
            throw new HttpException(401, 'Invalid signature');
        }
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            throw new HttpException(400, 'Invalid JSON');
        }
        app_log('webhooks', 'paystack event', ['event' => $event['event'] ?? null, 'reference' => $event['data']['reference'] ?? null]);
        return ['received' => true, 'type' => PaymentService::handlePaystackEvent($event)];
    }

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
