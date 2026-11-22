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

    /** Replace "X is required." with the wording a form wants, e.g. "Genre has not been selected." */
    protected static function missing(array $errors, array $messages): array
    {
        foreach ($messages as $field => $message) {
            if (isset($errors[$field]) && str_ends_with($errors[$field], 'is required.')) {
                $errors[$field] = $message;
            }
        }
        return $errors;
    }

    /**
     * Optional phone + network sent with a payment. With Paystack the buyer chooses card or
     * mobile money on Paystack's checkout, so both may be empty.
     */
    protected function paymentPhone(array $data): array
    {
        $phone = $data['phone'] ?? null;
        $provider = ($data['provider'] ?? null) ?: ($phone ? detect_provider($phone) : null);
        return [$phone ?: null, $provider];
    }
}
