<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal path router. Patterns use {name} placeholders that match a single path
 * segment and are passed to the handler as an associative array.
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, vars:string[], handler:callable}> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $vars = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$vars): string {
            $vars[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'vars'    => $vars,
            'handler' => $handler,
        ];
    }

    /** Dispatch a request. Returns whatever the matched handler returns. */
    public function dispatch(string $method, string $path): mixed
    {
        $path = '/' . trim(rawurldecode($path), '/');
        if ($path === '/') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $m)) {
                array_shift($m);
                $params = array_combine($route['vars'], $m) ?: [];
                return ($route['handler'])($params);
            }
        }

        http_response_code(404);
        if ($this->notFound !== null) {
            return ($this->notFound)([]);
        }
        return null;
    }
}
