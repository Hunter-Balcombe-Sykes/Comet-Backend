<?php

/**
 * Tests for DesignRationaleService — the transparency-line data. Under test:
 * the industry attribution line, the manual "You set this." line +
 * precedence, hasOverrides + summary copy, and the SAFETY contract — no raw
 * column name or internal key ever surfaces.
 */

use App\Services\Design\DesignRationaleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
});

/** A tenant with a sector (default: plumber — home_services + accent-only refinement). */
function rationaleTenant(string $handle, ?string $sector = 'plumber')
{
    $user = createTenant($handle);
    $user->sector = $sector;
    $user->sector_source = $sector === null ? null : 'manual';
    $user->save();

    return $user;
}

it('attributes the design to the industry when a sector is set', function () {
    $user = rationaleTenant('sector-trade');

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['sourceLabel'])->toBe('Your industry')
        ->and($out['items'][0]['reason'])->toBe('Your design reflects the industry you chose.')
        ->and($out['items'][0]['area'])->toContain('Colours')
        ->and($out['items'][0]['area'])->toContain('Typography')
        ->and($out['summary'])->toContain('tailored automatically');
});

it('puts the manual line first and drops overridden areas from the industry line', function () {
    $user = rationaleTenant('sector-override');
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
    ]);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeTrue()
        ->and($out['items'][0]['sourceLabel'])->toBe('You')
        ->and($out['items'][0]['reason'])->toBe('You set this.');

    $industry = collect($out['items'])->firstWhere('sourceLabel', 'Your industry');
    expect($industry)->not->toBeNull()
        ->and($industry['area'])->not->toContain('Colours'); // accent overridden
});

it('shows only the manual line when every preset column is overridden', function () {
    $user = rationaleTenant('all-manual');
    // Override every column the plumber overlay sets (home_services base +
    // accent refinement). Since the 2026-08-09 preset-only migration that is
    // accent and font — the body size, weight and radius columns the overlay
    // also used to set left the schema.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
        'typography_font_family' => 'geist',
    ]);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['sourceLabel'])->toBe('You')
        ->and($out['summary'])->toBe('Your design is set from your own choices.');
});

it('reads default-look copy when no sector and no overrides exist', function () {
    $user = rationaleTenant('no-signal', null);

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toBe([])
        ->and($out['summary'])
        ->toBe('Your design uses the default look — set your industry to tailor it automatically.');
});

it('never leaks a raw column name or internal key', function () {
    $user = rationaleTenant('safety');

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    $json = json_encode($out);
    expect($json)->not->toContain('color_accent')
        ->and($json)->not->toContain('typography_font_family')
        ->and($json)->not->toContain('sector');
});

/** #OBS-1: manualColumns() must report() a genuine read failure, not swallow it silently. */
it('reports a manualColumns() read failure and still fails closed', function () {
    Exceptions::fake();

    $user = rationaleTenant('read-failure');
    // Reproduce the fail-closed path the catch block exists for (SQLite test
    // mirror lacking site.design_kits) by dropping the table mid-test. Safe to
    // drop — TestCase::setUp() purges 'pgsql' to a brand-new :memory: handle
    // per test, so this never leaks into a sibling test.
    DB::connection('pgsql')->statement('DROP TABLE site.design_kits');

    $out = app(DesignRationaleService::class)
        ->forSite((string) $user->site->id, (string) $user->id);

    // Fail-closed means "no manual overrides recorded" — the sector-derived
    // preset line still renders (it doesn't depend on the design_kits read).
    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['sourceLabel'])->toBe('Your industry');
    // Assert on the specific design_kits failure by message rather than an
    // exact report count — reporting a QueryException can itself trigger
    // secondary context-gathering reads unrelated to this fix (e.g.
    // Nightwatch enrichment), which is out of scope here.
    Exceptions::assertReported(
        fn (QueryException $e) => str_contains($e->getMessage(), 'design_kits'),
    );
});

/** #CACHE-3: a repeat forSite() call on the SAME resolved instance must not re-read the DB. */
it('memoizes forSite() within the same resolved instance', function () {
    $user = rationaleTenant('memo-check');
    $service = app(DesignRationaleService::class);

    DB::connection('pgsql')->enableQueryLog();
    $first = $service->forSite((string) $user->site->id, (string) $user->id);
    $queriesAfterFirst = count(DB::connection('pgsql')->getQueryLog());

    $second = $service->forSite((string) $user->site->id, (string) $user->id);
    $queriesAfterSecond = count(DB::connection('pgsql')->getQueryLog());

    expect($second)->toBe($first)
        ->and($queriesAfterSecond)->toBe($queriesAfterFirst);
});
