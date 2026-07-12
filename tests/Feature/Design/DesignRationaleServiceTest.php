<?php

/**
 * Tests for DesignRationaleService — the transparency-line data (spec §3). Under
 * test: the friendly per-source sentences, the manual-override "You set this."
 * line + precedence, the hasOverrides flag + summary, and the SAFETY contract —
 * no raw column name, factor key, or sensitive/aesthetic detail ever surfaces.
 */

use App\Models\Core\Site\DesignKitContribution;
use App\Services\Design\DesignRationaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
    setupDesignKitContributionsTable();
});

/** Insert a contribution row directly (the resolver's output shape). */
function contribute(string $siteId, string $source, string $integration, int $priority, string $var, string $value, string $mode = 'auto'): void
{
    DesignKitContribution::query()->create([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'source' => $source,
        'integration' => $integration,
        'priority' => $priority,
        'mode' => $mode,
        'target_var' => $var,
        'value' => $value,
    ]);
}

it('produces friendly per-source reasons grouped by area', function () {
    $siteId = (string) Str::uuid();
    contribute($siteId, 'instagram:category', 'instagram', 30, 'color_accent', '#b8375a');
    contribute($siteId, 'instagram:category', 'instagram', 30, 'typography_font_family', 'origin');
    contribute($siteId, 'store:price-point', 'store', 58, 'space_regular', '0.85rem');

    $out = app(DesignRationaleService::class)->forSite($siteId);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toHaveCount(2);

    // Instagram drives Colours & Typography; store drives Layout.
    $byLabel = collect($out['items'])->keyBy('sourceLabel');
    expect($byLabel->has('Your Instagram'))->toBeTrue()
        ->and($byLabel['Your Instagram']['reason'])->toBe('Your colours and style adapt from your Instagram.')
        ->and($byLabel['Your Instagram']['area'])->toContain('Colours')
        ->and($byLabel['Your Instagram']['area'])->toContain('Typography')
        ->and($byLabel->has('Your store'))->toBeTrue()
        ->and($byLabel['Your store']['area'])->toBe('Layout');
});

it('labels a manually-overridden column "You set this." and drops its factor attribution', function () {
    $siteId = (string) Str::uuid();
    // A factor contributed the accent…
    contribute($siteId, 'instagram:category', 'instagram', 30, 'color_accent', '#b8375a');
    contribute($siteId, 'instagram:category', 'instagram', 30, 'typography_font_family', 'origin');
    // …but the user manually set the accent (and only the accent).
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'color_accent' => '#123456',
    ]);

    $out = app(DesignRationaleService::class)->forSite($siteId);

    expect($out['hasOverrides'])->toBeTrue();

    // The manual line is present and first.
    expect($out['items'][0]['sourceLabel'])->toBe('You')
        ->and($out['items'][0]['reason'])->toBe('You set this.');

    // Instagram is still attributed for TYPOGRAPHY (not overridden) but NOT for
    // Colours — the accent it contributed lost to the manual pick.
    $ig = collect($out['items'])->firstWhere('sourceLabel', 'Your Instagram');
    expect($ig)->not->toBeNull()
        ->and($ig['area'])->toBe('Typography')
        ->and($ig['area'])->not->toContain('Colours');
});

it('surfaces a launch recipe as a neutral "curated look" and aesthetic-expression neutrally', function () {
    $siteId = (string) Str::uuid();
    contribute($siteId, 'launch-recipe:archetype', 'launch-recipe', 70, 'effect_surface', 'glass', 'one_shot');
    contribute($siteId, 'aesthetic-expression:lean', 'aesthetic-expression', 64, 'weight_regular', '300', 'one_shot');

    $out = app(DesignRationaleService::class)->forSite($siteId);

    $labels = collect($out['items'])->pluck('reason')->all();
    // Recipe reason is neutral; aesthetic reason never hints at what it read.
    expect($labels)->toContain('A curated look chosen to match your profile.')
        ->and($labels)->toContain("Tuned to your profile's style.");
});

it('never surfaces raw column names, factor keys, or sensitive/demographic detail', function () {
    $siteId = (string) Str::uuid();
    // A spread across many sources, including the sensitive aesthetic one.
    contribute($siteId, 'aesthetic-expression:lean', 'aesthetic-expression', 64, 'effect_surface', 'glass', 'one_shot');
    contribute($siteId, 'instagram:category', 'instagram', 30, 'color_accent', '#b8375a');
    contribute($siteId, 'store:price-point', 'store', 58, 'space_regular', '0.85rem');
    contribute($siteId, 'hours-rhythm:energy', 'hours', 15, 'motion_pace', 'fast');

    $out = app(DesignRationaleService::class)->forSite($siteId);

    $blob = strtolower(json_encode($out));

    // No raw target_var column names.
    foreach (['effect_surface', 'color_accent', 'space_regular', 'motion_pace', 'weight_regular', 'target_var'] as $col) {
        expect(str_contains($blob, $col))->toBeFalse("rationale leaked a raw column name: {$col}");
    }
    // No factor keys / source strings.
    foreach (['aesthetic-expression', 'price-point', 'hours-rhythm', 'launch-recipe', ':lean', ':archetype'] as $key) {
        expect(str_contains($blob, $key))->toBeFalse("rationale leaked a factor key: {$key}");
    }
    // No sensitive/demographic vocabulary — the aesthetic source is neutralised.
    foreach (['gender', 'female', 'male', 'feminine', 'masculine', 'demographic', 'presentation', 'soft', 'bold'] as $sensitive) {
        expect(str_contains($blob, $sensitive))->toBeFalse("rationale leaked sensitive detail: {$sensitive}");
    }
});

it('summarises the default look when nothing has been resolved and nothing overridden', function () {
    $siteId = (string) Str::uuid();

    $out = app(DesignRationaleService::class)->forSite($siteId);

    expect($out['hasOverrides'])->toBeFalse()
        ->and($out['items'])->toBe([])
        ->and($out['summary'])->toContain('default look');
});

it('summarises auto-tailoring with an override note when both are present', function () {
    $siteId = (string) Str::uuid();
    contribute($siteId, 'instagram:category', 'instagram', 30, 'color_accent', '#b8375a');
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'border_radius' => '0.85rem',
    ]);

    $out = app(DesignRationaleService::class)->forSite($siteId);

    expect($out['hasOverrides'])->toBeTrue()
        ->and($out['summary'])->toContain('tailored automatically')
        ->and($out['summary'])->toContain('priority');
});

it('attributes only the WINNING source when two factors contest a column', function () {
    $siteId = (string) Str::uuid();
    // Two sources set the accent; the higher-priority one (recipe, 70) wins the
    // attribution, and the loser (instagram, 30) is not credited for Colours.
    contribute($siteId, 'launch-recipe:archetype', 'launch-recipe', 70, 'color_accent', '#c9a24b', 'one_shot');
    contribute($siteId, 'instagram:category', 'instagram', 30, 'color_accent', '#b8375a');

    $out = app(DesignRationaleService::class)->forSite($siteId);

    $labels = collect($out['items'])->pluck('sourceLabel')->all();
    expect($labels)->toContain('A curated look')
        ->and($labels)->not->toContain('Your Instagram'); // IG only set the losing accent
});
