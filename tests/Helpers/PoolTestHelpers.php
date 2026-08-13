<?php

use App\Http\Controllers\Api\Content\PoolController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The pool-lane fixture family. Lives here rather than inside PoolLaneTest.php
// because four other suites build pool fixtures too (the Media* trio and
// ServiceBackfillerTest). A function declared inside a Pest test file only
// exists once PHPUnit has included THAT file — fine serially, where discovery
// includes every test file before any of them run, but under `--parallel` each
// worker includes only its own assigned files, so a caller that lands in a
// worker without PoolLaneTest.php fatals with "Call to undefined function".
// Pest.php require_once's this file, so every worker has it. Same reasoning as
// MigrationColumnReplay's move to PSR-4 (#FFLAG-1 / TESTFX-1) and
// seedUserWithSite()'s move into Pest.php.

if (! function_exists('poolTenant')) {
    function poolTenant(): array
    {
        $pro = createTenant('pool-'.Str::lower(Str::random(6)));
        $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');

        return [$pro, $siteId];
    }
}

if (! function_exists('poolConnection')) {
    function poolConnection(string $userId, string $surfaceKey = 'youtube.channel', ?array $displaySettings = null): string
    {
        $id = (string) Str::uuid();
        DB::table('site.platform_connections')->insert([
            'id' => $id, 'user_id' => $userId, 'surface_key' => $surfaceKey,
            'routing_class' => 'content', 'resource_id' => 'acct-'.Str::random(6),
            'payload' => '{}', 'is_active' => 1,
            'display_settings' => $displaySettings === null ? null : json_encode($displaySettings),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolSource')) {
    function poolSource(string $userId, ?string $connectionId): string
    {
        $id = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $id, 'user_id' => $userId, 'kind' => $connectionId === null ? 'manual' : 'connection',
            'connection_id' => $connectionId, 'priority' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolItem')) {
    function poolItem(string $userId, string $sourceId, string $kind, string $headline, string $publishedAt): string
    {
        $id = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $id, 'user_id' => $userId, 'kind' => $kind,
            'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
            'first_seen_at' => now()->subDays(30), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'x:'.Str::random(8), 'item_id' => $id, 'kind' => $kind,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        DB::table('content.f_published')->insert([
            'item_id' => $id, 'source_id' => $sourceId,
            'published_from' => $publishedAt, 'updated_at' => now(),
        ]);

        return $id;
    }
}

if (! function_exists('poolGet')) {
    function poolGet(object $pro, string $pool = 'watch'): array
    {
        $request = Request::create("/api/content/pools/{$pool}", 'GET');
        $request->attributes->set('professional', $pro);

        return app(PoolController::class)->show($request, $pool)->getData(true);
    }
}

if (! function_exists('poolHeadlines')) {
    function poolHeadlines(array $payload, string $key = 'selection'): array
    {
        return array_column($payload[$key] ?? [], 'headline');
    }
}
