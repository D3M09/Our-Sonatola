<?php
declare(strict_types=1);

/** Tiny regex router with {param} placeholders. Business logic stays in controllers. */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:mixed}> */
    private array $routes = [];

    public function add(string $method, string $pattern, mixed $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$params) {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /**
     * @return array{handler:mixed,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        foreach ($this->routes as $r) {
            if ($r['method'] !== $method && $r['method'] !== 'ANY') {
                continue;
            }
            if (preg_match($r['regex'], $path, $m)) {
                $params = [];
                foreach ($r['params'] as $p) {
                    $params[$p] = $m[$p] ?? '';
                }
                return ['handler' => $r['handler'], 'params' => $params];
            }
        }
        return null;
    }
}
