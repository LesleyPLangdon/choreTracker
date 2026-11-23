<?php
declare(strict_types=1);

namespace App\Http;

/** Tiny router: patterns like /kids/{id}/pin, handlers are callables (Request, array $params). */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $methodsForPath = [];
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m)) {
                if ($method === $request->method) {
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    $result = $handler($request, $params);
                    return $result instanceof Response ? $result : new Response(200, $result);
                }
                $methodsForPath[] = $method;
            }
        }
        if ($methodsForPath) {
            throw new ApiException(405, 'Method not allowed.');
        }
        throw new ApiException(404, 'Not found.');
    }
}
