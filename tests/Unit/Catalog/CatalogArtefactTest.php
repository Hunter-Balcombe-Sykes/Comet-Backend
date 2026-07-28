<?php

use App\Catalog\CatalogIntegrityCheck;
use App\Catalog\CompiledCatalog;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// CI guard: the committed artefact must always match the definitions — a
// definitions edit without `catalog:compile` is unshippable.
it('has a committed artefact that matches the definitions', function () {
    expect(CompiledCatalog::isCompiled())->toBeTrue();

    Artisan::call('catalog:compile', ['--check' => true]);

    expect(Artisan::output())->toContain('matches definitions');
});

it('has no unservable surfaces and no orphan capabilities', function () {
    expect(CatalogIntegrityCheck::unservableSurfaces())->toBe([])
        ->and(CatalogIntegrityCheck::orphanCapabilities())->toBe([]);
});

it('compiles deterministically — a second compile is byte-identical', function () {
    // Compile to scratch paths: two fresh compiles prove the generator is
    // deterministic. The committed artefact stays byte-untouched — before
    // --out existed this test rewrote it in place, and pint's reformatting
    // meant every suite run left a huge cosmetic diff in the working tree.
    $committed = file_get_contents(CompiledCatalog::path());
    $first = tempnam(sys_get_temp_dir(), 'catalog-a-');
    $second = tempnam(sys_get_temp_dir(), 'catalog-b-');

    try {
        Artisan::call('catalog:compile', ['--out' => $first]);
        Artisan::call('catalog:compile', ['--out' => $second]);

        expect(file_get_contents($second))->toBe(file_get_contents($first))
            ->and(file_get_contents(CompiledCatalog::path()))->toBe($committed);
    } finally {
        @unlink($first);
        @unlink($second);
    }
});

it('ships only valid, well-formed detectors', function () {
    expect(CompiledCatalog::detectors())->toBeArray();

    foreach (CompiledCatalog::detectors() as $id => $detector) {
        // Patterns must compile — a malformed regex here would otherwise only
        // surface when the router meets a matching host in production.
        foreach (['path_pattern', 'subdomain_pattern'] as $field) {
            $pattern = $detector[$field] ?? null;
            if ($pattern !== null) {
                expect(@preg_match($pattern, ''))->not->toBeFalse("detector {$id}: invalid {$field} {$pattern}");
            }
        }
        foreach ($detector['reject_patterns'] as $reject) {
            expect(@preg_match($reject, ''))->not->toBeFalse("detector {$id}: invalid reject pattern {$reject}");
        }

        // A registrable key is a bare lowercase host, never a pattern.
        if ($detector['registrable_key'] !== null) {
            expect($detector['registrable_key'])->toMatch('/^[a-z0-9.-]+$/', "detector {$id}: registrable_key must be a bare lowercase host");
        }

        // A named capture promise must exist in the pattern that captures it
        // (query-sourced captures live in query_requires, not a regex group).
        if ($detector['identifier_capture'] !== null
            && in_array($detector['identifier_source'], ['path', 'subdomain'], true)) {
            $captured = str_contains(
                ($detector['path_pattern'] ?? '').($detector['subdomain_pattern'] ?? ''),
                "?<{$detector['identifier_capture']}>",
            );
            expect($captured)->toBeTrue("detector {$id}: identifier_capture '{$detector['identifier_capture']}' not captured by its own patterns");
        }
    }
});

it('gives every surface a routing class, shelf, and at least one detector or connect capability', function () {
    expect(CompiledCatalog::surfaces())->toBeArray();

    foreach (CompiledCatalog::surfaces() as $key => $surface) {
        expect($surface['routing_class'])->not->toBeEmpty()
            ->and($surface['shelf'])->not->toBeEmpty();

        $reachable = $surface['detectors'] !== []
            || isset($surface['capabilities']['connect'])
            || $surface['is_connectable'] === false;
        expect($reachable)->toBeTrue("surface {$key} is unreachable: no detector, no connect capability, yet connectable");
    }
});
