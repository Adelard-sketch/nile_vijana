<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal router.
 *   $r->get('/api/beats/{id}', [BeatController::class, 'show']);
 *   $r->post('/api/beats', [BeatController::class, 'store'], ['auth' => true, 'roles' => ['producer']]);
 *
 * Options:
 *   auth  => true        login required (Bearer token)
 *   auth  => 'optional'  attach the user if a token is sent
 *   roles => [...]       user must have at least one of these roles (admin always passes)
 *
 * {id} and any {somethingId} match digits only; other {names} match one path segment.
 */
final class Router
{
    private array $routes = [];

    public function get(string $p, array|callable $h, array $o = []): void    { $this->add('GET', $p, $h, $o); }
    public function post(string $p, array|callable $h, array $o = []): void   { $this->add('POST', $p, $h, $o); }
    public function put(string $p, array|callable $h, array $o = []): void    { $this->add('PUT', $p, $h, $o); }
    public function patch(string $p, array|callable $h, array $o = []): void  { $this->add('PATCH', $p, $h, $o); }
    public function delete(string $p, array|callable $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    public function add(string $method, string $pattern, array|callable $handler, array $options = []): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static function ($m) {
            $name = $m[1];
            $isId = $name === 'id' || str_ends_with($name, 'Id');
            return '(?P<' . $name . '>' . ($isId ? '\d+' : '[^/]+') . ')';
        }, $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $options];
    }

    public function dispatch(Request $req): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $options]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $allowed[] = $method;
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->authorize($req, $options);

            $result = is_array($handler)
                ? (new $handler[0]())->{$handler[1]}($req)
                : $handler($req);

            if ($result instanceof Response) {
                return $result;
            }
            return $result === null ? Response::noContent() : Response::json($result);
        }

        if ($allowed) {
            throw new HttpException(405, 'Method not allowed. Use: ' . implode(', ', array_unique($allowed)));
        }
        throw new HttpException(404, 'Route not found: ' . $req->method . ' ' . $req->path);
    }

    private function authorize(Request $req, array $options): void
    {
        $auth = $options['auth'] ?? false;
        if ($auth === false) {
            return;
        }
        $req->user = Auth::resolve($req);
        if ($auth === 'optional') {
            return;
        }
        if ($req->user === null) {
            throw new HttpException(401, 'Please sign in first');
        }
        $roles = $options['roles'] ?? [];
        if ($roles && !Auth::hasRole($req->user, ...$roles)) {
            throw HttpException::forbidden('This needs one of these roles: ' . implode(', ', $roles));
        }
    }
}
