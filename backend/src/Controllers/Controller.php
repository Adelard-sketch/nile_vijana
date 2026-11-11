<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

/** Shared helpers for controllers. */
abstract class Controller
{
    /** Allow the owner of a record, or an admin. Others get 404 (so ids can't be probed). */
    protected function ownerOrAdmin(Request $req, int $ownerId, string $what = 'Resource'): void
    {
        if ($req->userId() !== $ownerId && !Auth::isAdmin($req->user)) {
            throw HttpException::notFound($what);
        }
    }

    /** Standard list response: {"data": [...], "meta": {...}} */
    protected function paginated(array $rows, int $page, int $perPage, int $total): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    /** Phone + provider for a mobile money payment from the request body. */
    protected function paymentPhone(array $data): array
    {
        $phone = $data['phone'];
        $provider = $data['provider'] ?? null ?: detect_provider($phone);
        if (!$provider) {
            throw HttpException::invalid('Choose your mobile money network', ['provider' => 'MTN_MOMO_RWA or AIRTEL_RWA']);
        }
        return [$phone, $provider];
    }
}
