<?php
declare(strict_types=1);

/*
 * Front controller: every /api request comes through this file.
 *
 * XAMPP / Apache: the .htaccess files send requests here automatically.
 * PHP built-in server (quick local testing):
 *     php -S localhost:8000 -t public public/index.php
 */

// Let the built-in server deliver real files (uploaded images) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('~(?:^|/)api(?:/|$)~', $requestPath) !== 1) {
    require __DIR__ . '/home.php';
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// ---- CORS (lets your frontend on another port/domain call the API) ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = (array) config('app.cors_origins', []);
if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-HTTP-Method-Override, Range');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('X-Content-Type-Options: nosniff');

try {
    $request = Request::capture();
    $router = new Router();
    require BASE_PATH . '/src/routes.php';
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $payload = ['error' => $e->getMessage()];
    if ($e->errors) {
        $payload['errors'] = $e->errors;
    }
    $response = Response::json($payload, $e->status);
} catch (Throwable $e) {
    app_log('errors', $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    $payload = ['error' => 'Something went wrong on the server'];
    if (config('app.debug')) {
        $payload['debug'] = [
            'message' => $e->getMessage(),
            'file'    => str_replace(BASE_PATH, '', $e->getFile()),
            'line'    => $e->getLine(),
        ];
    }
    $response = Response::json($payload, 500);
}

$response->send();
