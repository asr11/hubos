<?php

declare(strict_types=1);

namespace HubOS\Core;

/** Minimal method + path router with {param} and {param:regex} placeholders. */
final class Router
{
    /** @var list<array{method:string, path:string, regex:string, handler:mixed, opts:array<string,mixed>}> */
    private array $routes = [];

    /** @param array<string, mixed> $opts */
    public function get(string $path, mixed $handler, array $opts = []): void
    {
        $this->add('GET', $path, $handler, $opts);
    }

    /** @param array<string, mixed> $opts */
    public function post(string $path, mixed $handler, array $opts = []): void
    {
        $this->add('POST', $path, $handler, $opts);
    }

    /** @param array<string, mixed> $opts */
    public function add(string $method, string $path, mixed $handler, array $opts = []): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => $path,
            'regex'   => self::compile($path),
            'handler' => $handler,
            'opts'    => $opts,
        ];
    }

    /**
     * @return array{status:int, handler?:mixed, params?:array<string,string>, opts?:array<string,mixed>, allow?:list<string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, static fn ($k): bool => is_string($k), ARRAY_FILTER_USE_KEY);
            return ['status' => 200, 'handler' => $route['handler'], 'params' => $params, 'opts' => $route['opts']];
        }

        if ($allowed !== []) {
            return ['status' => 405, 'allow' => array_values(array_unique($allowed))];
        }
        return ['status' => 404];
    }

    private static function compile(string $path): string
    {
        $regex = '';
        $offset = 0;
        if (preg_match_all('#\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}#', $path, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $regex .= preg_quote(substr($path, $offset, $m[0][1] - $offset), '#');
                $pattern = (isset($m[2]) && $m[2][1] !== -1) ? $m[2][0] : '[^/]+';
                $regex .= '(?P<' . $m[1][0] . '>' . $pattern . ')';
                $offset = $m[0][1] + strlen($m[0][0]);
            }
        }
        $regex .= preg_quote(substr($path, $offset), '#');
        return '#^' . $regex . '$#';
    }
}
