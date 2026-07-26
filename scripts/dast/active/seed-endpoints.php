<?php

// Active lane: transform `php artisan route:list --json` into a ZAP-importable
// seed. Two formats: a minimal OpenAPI 3 doc (default — ZAP's `openapi` import
// expands path params + methods) and a flat URL list (documented fallback).
// Usage: php seed-endpoints.php <routes-raw.json> <base-url> <outdir>

[, $routesPath, $baseUrl, $outdir] = $argv;

$routes = json_decode(file_get_contents($routesPath), true);

// Drop non-api surface, Boost's debug routes, health checks, and any
// catch-all/asset route that would slip in if the route table ever grows
// one — none currently exist under api/, but the plan calls these out
// explicitly so the filter stays defensive.
$excludePrefixes = ['api/_boost', 'api/up'];
$excludePatterns = ['/\{path\}/', '/\.(js|css|map)$/'];

$paths = [];
$urls = [];

foreach ($routes as $route) {
    $uri = $route['uri'];
    if (! str_starts_with($uri, 'api/')) {
        continue;
    }
    $skip = false;
    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($uri, $prefix)) {
            $skip = true;
            break;
        }
    }
    foreach ($excludePatterns as $pattern) {
        if (preg_match($pattern, $uri)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $methods = array_filter(explode('|', $route['method']), fn ($m) => $m !== 'HEAD');
    $openApiPath = '/'.preg_replace('/\{(\w+)\??\}/', '{$1}', $uri);

    // Path parameters, declared so ZAP's OpenAPI import expands/fuzzes them
    // itself. Phase 3's seeded resource ids are not wired in here (Phase 2
    // depends only on Phase 1; Phase 3 depends on Phase 2, not the reverse
    // — see plan) — Phase 4's context/exclusion pass is where real ids get
    // used for the cross-identity IDOR targets.
    preg_match_all('/\{(\w+)\??\}/', $uri, $paramMatches);
    $parameters = array_map(
        fn ($name) => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        $paramMatches[1]
    );

    $flatUrl = preg_replace('/\{(\w+)\??\}/', '1', $uri);

    if (! isset($paths[$openApiPath])) {
        $paths[$openApiPath] = [];
    }
    foreach ($methods as $method) {
        $operation = [
            'operationId' => ($route['name'] ?: str_replace(['/', '{', '}'], ['_', '', ''], $uri)).'_'.strtolower($method),
            'responses' => ['200' => ['description' => 'OK']],
        ];
        if ($parameters) {
            $operation['parameters'] = $parameters;
        }
        $paths[$openApiPath][strtolower($method)] = $operation;
        $urls[] = "$method $baseUrl/$flatUrl";
    }
}

$openapi = [
    'openapi' => '3.0.0',
    'info' => ['title' => 'Partna API (DAST seed)', 'version' => '1.0'],
    'servers' => [['url' => $baseUrl]],
    'paths' => $paths,
];

file_put_contents("$outdir/seed-openapi.json", json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents("$outdir/seed-urls.txt", implode("\n", $urls)."\n");

fwrite(STDERR, '[dast] seed-endpoints: '.count($paths)." paths, ".count($urls)." method+URL entries\n");
