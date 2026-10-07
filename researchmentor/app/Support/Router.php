<?php
declare(strict_types=1);

namespace App\Support;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:array,middleware:array,pattern:string}> */
    private array $routes = [];
    private array $groupMiddleware = [];
    private string $prefix = '';

    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $prevP = $this->prefix;
        $prevM = $this->groupMiddleware;
        $this->prefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $callback($this);
        $this->prefix = $prevP;
        $this->groupMiddleware = $prevM;
    }

    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $full = rtrim($this->prefix . $pattern, '/') ?: '/';
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $full) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'pattern' => $full,
        ];
    }

    public function get(string $p, array $h, array $m = []): void { $this->add('GET', $p, $h, $m); }
    public function post(string $p, array $h, array $m = []): void { $this->add('POST', $p, $h, $m); }
    public function put(string $p, array $h, array $m = []): void { $this->add('PUT', $p, $h, $m); }
    public function delete(string $p, array $h, array $m = []): void { $this->add('DELETE', $p, $h, $m); }

    /** @return array{handler:array,middleware:array,params:array}|null */
    public function match(string $method, string $path): ?array
    {
        $allowed = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                if ($r['method'] !== strtoupper($method)) {
                    $allowed = true;
                    continue;
                }
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['handler' => $r['handler'], 'middleware' => $r['middleware'], 'params' => $params];
            }
        }
        if ($allowed) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.');
        }
        return null;
    }

    public function routes(): array { return $this->routes; }
}
