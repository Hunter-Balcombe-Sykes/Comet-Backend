<?php

/**
 * Tests for DesignRationaleService — the transparency-line data. Under test:
 * the industry attribution line, the manual "You set this." line +
 * precedence, hasOverrides + summary copy, and the SAFETY contract — no raw
 * column name or internal key ever surfaces.
 */

use App\Services\Design\DesignRationaleService;
use Illuminate\Support\Facades\DB;

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
    // accent refinement): accent, font, body size (+ desktop pair), weight, radius.
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $user->site->id,
        'color_accent' => '#123456',
        'typography_font_family' => 'geist',
        'text_body' => '0.8rem',
        'text_desktop_body' => '0.8rem',
        'weight_regular' => '400',
        'border_radius' => '0.5rem',
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
