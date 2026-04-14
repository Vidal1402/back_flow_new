<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /**
     * @var array<int, array{method:string, pattern:string, handler:callable, middlewares:array<int, callable>}>
     */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(Request $request): void
    {
        if ($request->method === 'OPTIONS') {
            Response::json(['ok' => true], 200);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            $context = [];
            foreach ($route['middlewares'] as $middleware) {
                $result = $middleware($request, $params, $context);
                if (is_array($result)) {
                    $context = array_merge($context, $result);
                }
            }

            ($route['handler'])($request, $params, $context);
            return;
        }

        Response::json(['error' => 'not_found', 'message' => 'Rota não encontrada'], 404);
    }

    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $matches): string {
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $pattern);

        if (!is_string($regex)) {
            return null;
        }

        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }
}
