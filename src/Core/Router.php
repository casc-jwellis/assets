<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Route table. Modules register routes here.
 *
 * Handlers are callables: fn(Request $request, array $params): string
 *
 * Options:
 *   public => true   reachable without logging in (login page only, normally)
 *   admin  => true   only users with the admin flag
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:callable, opts:array}> */
    private array $routes = [];

    public function get(string $path, callable $handler, array $opts = []): void
    {
        $this->add('GET', $path, $handler, $opts);
    }

    public function post(string $path, callable $handler, array $opts = []): void
    {
        $this->add('POST', $path, $handler, $opts);
    }

    public function add(string $method, string $path, callable $handler, array $opts = []): void
    {
        // "/assets/{id}" -> named capture group matching one path segment.
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
            'opts'    => $opts,
        ];
    }

    /**
     * @return array{status:int, handler?:callable, opts?:array, params?:array<string,string>}
     *         status 200 = matched, 404 = no such path, 405 = path exists for other methods
     */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['status' => 200, 'handler' => $route['handler'], 'opts' => $route['opts'], 'params' => $params];
        }
        return ['status' => $pathMatched ? 405 : 404];
    }
}
