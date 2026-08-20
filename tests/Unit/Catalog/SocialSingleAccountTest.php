<?php

use App\Catalog\CompiledCatalog;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Owner decree 2026-08-20 (scan-refinement run, FI-1): every SOCIAL surface is
// single-account. A scan that finds a second Instagram/TikTok/etc. while one
// is connected must produce a Swap suggestion (SourceReconciler::capReached
// records conflicting_connection_id only when max_accounts <= 1), never a
// second connection. This pins the whole class so a future social definition
// can't quietly reintroduce multiAccount() and resurrect the "+1" bug.
it('keeps every social surface single-account', function () {
    $socials = array_filter(
        CompiledCatalog::surfaces(),
        fn (array $surface): bool => ($surface['routing_class'] ?? null) === 'social',
    );

    expect($socials)->not->toBeEmpty();

    foreach ($socials as $surface) {
        expect($surface['max_accounts'] ?? 1)->toBe(1, "social surface {$surface['key']} must be single-account");
    }
});
