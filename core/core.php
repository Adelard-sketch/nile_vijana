<?php
/**
 * core.php — Bootstrap for simple PHP pages outside the backend framework.
 * Include this file at the top of any page that needs DB access or Paystack.
 *
 * Usage:
 *   require_once __DIR__ . '/../core/core.php';
 */

declare(strict_types=1);

// Load credentials
require_once __DIR__ . '/db_cred.php';
require_once __DIR__ . '/paystack_credential.php';

// Make the DB connection available globally
require_once __DIR__ . '/db_class.php';

// Make the Paystack client available globally
require_once __DIR__ . '/PaystackClient.php';
