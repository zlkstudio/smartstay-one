<?php
declare(strict_types=1);

namespace One\Http;

final class Router
{
    /** @var list<array{method:string, pattern:string, handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** Path params: /users/{id} → handler receives ['id' => '12']. Only digits are matched. */
    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $path) . '$#';
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $method = $method === 'HEAD' ? 'GET' : $method;

        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            ($route['handler'])($params);
            return;
        }

        if ($pathMatched) {
            http_response_code(405);
            header('Allow: GET, POST');
            exit('Metodă nepermisă.');
        }

        Guard::notFound($path);
    }
}
