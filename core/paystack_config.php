<?php
/**
 * paystack_config.php — Paystack settings.
 * Keys are defined in paystack_credential.php (loaded by core.php).
 */

define('PAYSTACK_BASE_URL', 'https://api.paystack.co');

// Payment channels shown on the Paystack checkout.
define('PAYSTACK_CHANNELS', ['card', 'mobile_money', 'bank_transfer']);

// Currency for all transactions.
define('PAYSTACK_CURRENCY', 'GHS');
