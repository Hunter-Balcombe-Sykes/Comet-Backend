<?php

// "Restyle from brand" end to end (plan §13): preview diff → explicit apply
// with an undo snapshot in site.design_kit_restyles → undo restores exactly
// what was displaced. Route-level throughout — direct controller calls have
// hidden live bugs before (house rule).

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupDesignKitsTable();
    setupDesignKitRestylesTable();
    setupSectionsTables();
});

function restyleSite(string $handle): object
{
    $pro = createTenant($handle);

    DB::table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => (string) $pro->site->id,
        'usage' => SiteMedia::USAGE_DESIGN,
        'purpose' => SiteMedia::PURPOSE_LOGO_FULL,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'palette' => json_encode(['dominant' => '#e0491f', 'colors' => ['#e0491f'], 'warm' => false]),
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $pro;
}

it('previews the brand diff, flagging what the user has set', function () {
    $pro = restyleSite('restyle-preview');
    DB::table('site.design_kits')->insert([
        'site_id' => $pro->site->id, 'color_accent' => '#123456',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/site/restyle/preview');

    $response->assertOk();
    $change = collect($response->json('restyle.changes'))->firstWhere('column', 'color_accent');
    expect($change['current'])->toBe('#123456')
        ->and($change['currentIsManual'])->toBeTrue()
        ->and($change['proposed'])->not->toBeNull();
});

it('gives honest empty-state copy for a neutral wordmark', function () {
    $pro = createTenant('restyle-neutral');
    DB::table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => (string) $pro->site->id,
        'usage' => SiteMedia::USAGE_DESIGN,
        'purpose' => SiteMedia::PURPOSE_LOGO_FULL,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'palette' => json_encode(['dominant' => '#0a0a0a', 'colors' => ['#0a0a0a'], 'warm' => false]),
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/site/restyle/preview');

    $response->assertOk();
    expect($response->json('restyle.changes'))->toBe([])
        ->and($response->json('restyle.reason'))->toBe('neutral_wordmark');
});

it('applies chosen columns, snapshotting the displaced kit for undo', function () {
    $pro = restyleSite('restyle-apply');
    DB::table('site.design_kits')->insert([
        'site_id' => $pro->site->id, 'color_accent' => '#123456',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = actingAsUser($pro)->postJson('/api/site/restyle', ['columns' => ['color_accent']]);

    $response->assertStatus(201);
    $kit = DB::table('site.design_kits')->where('site_id', $pro->site->id)->first();
    $snapshot = json_decode((string) DB::table('site.design_kit_restyles')->value('snapshot'), true);

    expect($kit->color_accent)->not->toBe('#123456')
        ->and($snapshot['color_accent'])->toBe('#123456')
        ->and((int) DB::table('site.site_build_state')->where('site_id', $pro->site->id)->value('content_revision'))
        ->toBeGreaterThan(0);
});

it('refuses an apply when nothing it names has a brand value', function () {
    // A neutral wordmark proposes nothing — applying against it must 422,
    // never write an empty restyle row.
    $pro = createTenant('restyle-nothing');

    actingAsUser($pro)->postJson('/api/site/restyle', ['columns' => ['color_accent']])
        ->assertStatus(422);

    expect(DB::table('site.design_kit_restyles')->count())->toBe(0);
});

it('rejects a column outside the closed writable set', function () {
    $pro = restyleSite('restyle-badcol');

    actingAsUser($pro)->postJson('/api/site/restyle', ['columns' => ['theme_night_shift_auto']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('columns.0');
});

it('undoes a restyle back to the exact displaced values', function () {
    $pro = restyleSite('restyle-undo');
    DB::table('site.design_kits')->insert([
        'site_id' => $pro->site->id, 'color_accent' => '#123456',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $applied = actingAsUser($pro)->postJson('/api/site/restyle', ['columns' => ['color_accent']]);
    $restyleId = $applied->json('restyle.id');

    $response = actingAsUser($pro)->postJson("/api/site/restyle/{$restyleId}/undo");

    $response->assertOk();
    expect(DB::table('site.design_kits')->where('site_id', $pro->site->id)->value('color_accent'))
        ->toBe('#123456')
        ->and($response->json('restyle.undoneAt'))->not->toBeNull();
});

it('refuses a second undo of the same restyle', function () {
    $pro = restyleSite('restyle-undo2');

    $restyleId = actingAsUser($pro)->postJson('/api/site/restyle', ['columns' => ['color_accent']])
        ->json('restyle.id');

    actingAsUser($pro)->postJson("/api/site/restyle/{$restyleId}/undo")->assertOk();
    actingAsUser($pro)->postJson("/api/site/restyle/{$restyleId}/undo")->assertStatus(422);
});

it('404s another user\'s restyle rather than confirming it exists', function () {
    $owner = restyleSite('restyle-owner');
    $restyleId = actingAsUser($owner)->postJson('/api/site/restyle', ['columns' => ['color_accent']])
        ->json('restyle.id');

    $intruder = createTenant('restyle-intruder');

    actingAsUser($intruder)->postJson("/api/site/restyle/{$restyleId}/undo")->assertStatus(404);
});
