<?php

use App\Models\Core\Site\Block;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    setupMediaTables();
    setupServicesTable();
    setupUserIntegrationsTable();
    shimPgAdvisoryLockForSqlite();
});

it('batchCheck issues exactly one SQL query regardless of section count', function () {
    $pro = createBrandTenant('one-query-batch');

    // Build a Block collection with all 9 requirement-bearing types (gallery,
    // documents, services, booking, countdown, contact, workplace, credentials, experience).
    // workplace/credentials/experience were added in FOUND-4/5 — portable Eloquent subqueries
    // that compile on SQLite so the single-query invariant holds for all 9.
    $types = ['gallery', 'documents', 'services', 'booking', 'countdown', 'contact', 'workplace', 'credentials', 'experience'];
    $blocks = collect();
    $now = now()->toDateTimeString();
    foreach ($types as $i => $type) {
        $blocks->push(new Block([
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'site_id' => $pro->site->id,
            'block_group' => 'sections',
            'block_type' => $type,
            'sort_order' => $i,
            'is_active' => false,
            'is_enabled' => true,
            'settings' => [],
        ]));
    }

    $queries = [];
    DB::connection('pgsql')->listen(function ($q) use (&$queries) {
        $queries[] = strtolower(trim((string) $q->sql));
    });

    app(SectionVisibilityService::class)->batchCheck(
        (string) $pro->id,
        (string) $pro->site->id,
        $blocks,
    );

    // The combined SELECT runs as exactly one statement and should contain
    // multiple `exists (...)` subqueries inside it.
    expect($queries)->toHaveCount(1);
    expect($queries[0])->toStartWith('select exists');
    // 5 original (gallery, document, priced-service, active-service, booking-link)
    // + 3 new portable subqueries (workplace, credential, experience) = 8.
    expect(substr_count($queries[0], 'exists ('))->toBeGreaterThanOrEqual(8);
});
