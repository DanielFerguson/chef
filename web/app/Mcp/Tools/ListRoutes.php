<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListRoutes extends Tool
{
    protected string $description = 'List application routes, optionally filtered by name, path, or HTTP method.';

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Optional substring to match against route names.'),
            'path' => $schema->string()
                ->description('Optional substring to match against route URIs.'),
            'method' => $schema->string()
                ->description('Optional HTTP method such as GET, POST, PUT, PATCH, or DELETE.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $name = mb_strtolower(trim((string) $request->get('name', '')));
        $path = mb_strtolower(trim((string) $request->get('path', '')));
        $method = mb_strtoupper(trim((string) $request->get('method', '')));
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routeName = $route->getName();
            $uri = $route->uri();
            $methods = $route->methods();

            if ($name !== '' && ! str_contains(mb_strtolower((string) $routeName), $name)) {
                continue;
            }

            if ($path !== '' && ! str_contains(mb_strtolower($uri), $path)) {
                continue;
            }

            if ($method !== '' && ! in_array($method, $methods, true)) {
                continue;
            }

            $routes[] = [
                'methods' => $methods,
                'uri' => $uri,
                'name' => $routeName,
                'action' => $route->getActionName(),
                'middleware' => $route->gatherMiddleware(),
            ];
        }

        return Response::json($routes);
    }
}
