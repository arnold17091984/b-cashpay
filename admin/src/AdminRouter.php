<?php

declare(strict_types=1);

namespace BCashPay\Admin;

/**
 * Simple pattern-matching router for the admin panel.
 *
 * Supports literal paths and named segments like {id} and {slug}.
 */
class AdminRouter
{
    /** @var array<int, array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Dispatch the current request.
     * Returns false if no route matched.
     */
    public function dispatch(string $method, string $path): bool
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                ($route['handler'])(...array_values($params));
                return true;
            }
        }

        return false;
    }

    /**
     * Match a path against a pattern.
     * Returns an associative array of named params, or null if no match.
     *
     * Pattern: /payments/{id}/cancel  →  matches /payments/01ABC123/cancel
     *
     * @return array<string, string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        // Build regex from pattern
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        // Return only named captures
        return array_filter(
            $matches,
            fn($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );
    }
}
