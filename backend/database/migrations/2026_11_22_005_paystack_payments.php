<?php
declare(strict_types=1);

/*
 * Paystack (Ghana): the buyer pays on Paystack's secure checkout (card or MTN / Telecel /
 * AirtelTigo mobile money), so the phone number is no longer needed up front.
 *  - payments: Paystack checkout link and access code, payer email, channel used.
 *  - payments/orders: phone and network become optional.
 *  - payouts: Paystack transfer recipient, and who marked a manual payout as sent.
 */

use App\Core\DB;
use App\Support\Migrator;

return static function (Migrator $m): void {
    $m->addColumn('payments', 'email', 'VARCHAR(190) NULL AFTER phone');
    $m->addColumn('payments', 'checkout_url', 'VARCHAR(500) NULL AFTER email');
    $m->addColumn('payments', 'access_code', 'VARCHAR(100) NULL AFTER checkout_url');
    $m->addColumn('payments', 'channel', 'VARCHAR(30) NULL AFTER access_code');
    DB::pdo()->exec('ALTER TABLE payments MODIFY provider VARCHAR(30) NULL, MODIFY phone VARCHAR(20) NULL');
    DB::pdo()->exec('ALTER TABLE orders MODIFY phone VARCHAR(20) NULL, MODIFY provider VARCHAR(30) NULL');
    $m->addColumn('payouts', 'recipient_code', 'VARCHAR(60) NULL AFTER provider');
    $m->addColumn('payouts', 'sent_note', 'VARCHAR(255) NULL AFTER failure_reason');
    $m->log('~ payments, orders and payouts ready for Paystack');
};
