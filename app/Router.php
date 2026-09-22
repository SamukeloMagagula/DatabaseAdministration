<?php

namespace App;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        return null;
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
