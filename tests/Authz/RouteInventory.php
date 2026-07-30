<?php

namespace Tests\Authz;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Derives the route surface from the LIVE router on every run.
 *
 * Deliberately not a checked-in list: a route added to routes/api/*.php is in
 * the matrix on the next run with no registration step, which is the property
 * that stops this harness decaying.
 *
 * Reflection resolves WHICH of identity B's rows to substitute. It does NOT
 * decide whether a route is tested. Everything carrying a {param} is tested.
 * Measured 2026-07-30: of 142 distinct param-bearing patterns, reflection
 * resolves only 64 — the other 78 (api/enquiries/{id}, api/site/sections/
 * {section}, most of api/platforms) fetch by hand. An "unresolved means not
 * tenant-scoped" rule would have excluded exactly those and reported green.
 */
final class RouteInventory
{
    /** @return array<int, RouteCase> */
    public static function all(): array
    {
        $cases = [];

        foreach (app('router')->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $params = self::resolveParams($route);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $cases[] = new RouteCase(
                    method: $method,
                    uri: $uri,
                    action: $route->getActionName(),
                    params: $params,
                );
            }
        }

        return $cases;
    }

    /** @return array<string, string|null> */
    private static function resolveParams(LaravelRoute $route): array
    {
        $names = $route->parameterNames();

        if ($names === []) {
            return [];
        }

        $byName = self::modelsInSignature($route->getActionName());

        $params = [];
        foreach ($names as $name) {
            $params[$name] = $byName[$name] ?? null;
        }

        return $params;
    }

    /** @return array<string, string> param name => model FQCN */
    private static function modelsInSignature(string $action): array
    {
        if ($action === 'Closure' || ! str_contains($action, '@')) {
            return [];
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class)) {
            return [];
        }

        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [];
        }

        $models = [];
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (is_subclass_of($type->getName(), Model::class)) {
                $models[$param->getName()] = $type->getName();
            }
        }

        return $models;
    }
}
