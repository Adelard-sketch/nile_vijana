<?php
declare(strict_types=1);

/*
 * Loaded by public/index.php (web) and bin/worker.php (command line).
 * Sets up autoloading, configuration and the time zone.
 */

define('BASE_PATH', dirname(__DIR__));

// PSR-4 style autoloader: App\Core\Router -> src/Core/Router.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/src/helpers.php';

// Secrets (Paystack keys, SMTP password, Spotify credentials) live in backend/.env,
// never in the code or in the frontend. Real environment variables win over the file.
load_env_file(BASE_PATH . '/.env');
// Never let a deployed .env.local replace production credentials. Local HTTP
// requests can use the XAMPP override; CLI users can opt in with NV_USE_LOCAL_ENV=true.
$localRequest = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if ($localRequest || env('NV_USE_LOCAL_ENV', false) === true) {
    load_env_file(BASE_PATH . '/.env.local', true);
}

$configFile = BASE_PATH . '/config/config.php';
if (!is_file($configFile)) {
    $msg = 'Missing config/config.php. Copy config/config.example.php to config/config.php and edit it.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

App\Core\Config::load(require $configFile);
date_default_timezone_set((string) config('app.timezone', 'Africa/Accra'));

if (config('app.env') === 'production' && str_starts_with((string) config('app.key'), 'CHANGE_ME')) {
    throw new RuntimeException('Set app.key in config/config.php before running in production.');
}
