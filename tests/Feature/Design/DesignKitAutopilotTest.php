<?php

// Design-kit autopilot (WAVE-2C item 3; plan §13): fromBrandPalette +
// fromWebsiteEvidence, and the fill-if-empty persister they share.
//
// The properties that matter: real WCAG math (a brand colour that cannot
// read gets TONED, not faked), the neutral-wordmark no-op (a black wordmark
// must never invent an accent), and fill-if-empty (a set column — however it
// was set — is manual and untouchable).

use App\Models\Core\Site\SiteMedia;
use App\Services\Design\DesignKitAutopilot;
use App\Services\Design\WcagContrast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupMediaTables();
    setupDesignKitsTable();
    setupSectionsTables(); // BuildState::bump target
});

function autopilotSite(array $palette = ['dominant' => '#e0491f', 'colors' => ['#e0491f', '#ffffff'], 'saturation' => 0.8, 'warm' => false]): string
{
    $pro = createTenant('autopilot-'.Str::lower(Str::random(6)));
    $siteId = (string) $pro->site->id;

    DB::table('site.site_media')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_DESIGN,
        'purpose' => SiteMedia::PURPOSE_LOGO_FULL,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'palette' => json_encode($palette),
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $siteId;
}

// ── fromBrandPalette ─────────────────────────────────────────────────────────

it('derives an accent from the persisted logo palette', function () {
    $siteId = autopilotSite();

    $derived = app(DesignKitAutopilot::class)->fromBrandPalette($siteId);

    expect($derived['reason'])->toBeNull()
        ->and($derived['proposals']['color_accent'] ?? null)->not->toBeNull();
});

it('tones a too-light brand colour until it actually reads', function () {
    // #ff9d9d qualifies as an accent (saturated, mid-luminance) but fails
    // WCAG AA on white. §13 demands real contrast math — the proposal must
    // be a darkened member of the same hue, not the original and not nothing.
    $siteId = autopilotSite(['dominant' => '#ff9d9d', 'colors' => ['#ff9d9d'], 'warm' => false]);

    $accent = app(DesignKitAutopilot::class)->fromBrandPalette($siteId)['proposals']['color_accent'];

    expect(WcagContrast::ratio($accent, '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and($accent)->not->toBe('#ff9d9d');
});

it('gracefully no-ops on a neutral black wordmark', function () {
    $siteId = autopilotSite(['dominant' => '#111111', 'colors' => ['#111111', '#fafafa'], 'warm' => false]);

    $derived = app(DesignKitAutopilot::class)->fromBrandPalette($siteId);

    expect($derived['proposals'])->toBe([])
        ->and($derived['reason'])->toBe(DesignKitAutopilot::REASON_NEUTRAL_WORDMARK);
});

it('says so when no processed palette exists yet', function () {
    $pro = createTenant('autopilot-nopal');

    $derived = app(DesignKitAutopilot::class)->fromBrandPalette((string) $pro->site->id);

    expect($derived['proposals'])->toBe([])
        ->and($derived['reason'])->toBe(DesignKitAutopilot::REASON_NO_PALETTE);
});

it('proposes no room at all for a warm palette — one palette survives', function () {
    // Until 2026-08-06 a warm palette proposed theme_mode 'warm'. The mode
    // column is gone with the design-kit simplification, and 'warm' was never
    // a mode the sitepage renderer knew, so it only ever fell back anyway.
    $siteId = autopilotSite(['dominant' => '#b0521a', 'colors' => ['#b0521a'], 'warm' => true]);

    $proposals = app(DesignKitAutopilot::class)->fromBrandPalette($siteId)['proposals'];

    expect($proposals)->not->toHaveKey('theme_mode')
        ->and($proposals['color_accent'] ?? null)->not->toBeNull();
});

// ── fromWebsiteEvidence ──────────────────────────────────────────────────────

it('classifies a Google-Fonts site onto the roster register', function () {
    $html = '<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600" rel="stylesheet">';

    $derived = app(DesignKitAutopilot::class)->fromWebsiteEvidence($html);

    expect($derived['proposals'])->toBe(['typography_font_family' => 'forma-djr']);
});

it('reads font-family declarations out of inline CSS', function () {
    $html = '<style>body { font-family: "Helvetica Neue", Arial, sans-serif; }</style>';

    $derived = app(DesignKitAutopilot::class)->fromWebsiteEvidence($html);

    expect($derived['proposals'])->toBe(['typography_font_family' => 'helvetica-neue']);
});

it('refuses to guess when two registers tie', function () {
    $html = '<style>h1 { font-family: Poppins; } p { font-family: "Open Sans"; }</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals'])->toBe([]);
});

it('proposes nothing for a page with no font evidence', function () {
    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence('<p>hello</p>')['proposals'])->toBe([]);
});

// ── corners + spacing from scanned CSS (plan 02 step 5) ──────────────────────

it('reads a hard-edged site as sharp corners', function () {
    $html = '<style>.a{border-radius:0px}.b{border-radius:2px}.c{border-radius:1px}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals']['corners'] ?? null)
        ->toBe('sharp');
});

it('reads a soft consumer site as rounded corners, ignoring pill sentinels', function () {
    $html = '<style>.a{border-radius:16px}.b{border-radius:12px}.chip{border-radius:9999px}.c{border-radius:1.25rem}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals']['corners'] ?? null)
        ->toBe('rounded');
});

it('reads a mid-radius site as default corners — a real proposal, since evidence beats the sector look', function () {
    $html = '<style>.a{border-radius:6px}.b{border-radius:4px}.c{border-radius:8px}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals']['corners'] ?? null)
        ->toBe('default');
});

it('proposes no corners below the evidence floor (fewer than 3 declarations)', function () {
    $html = '<style>.a{border-radius:0px}.b{border-radius:0px}</style>';
    $derived = app(DesignKitAutopilot::class)->fromWebsiteEvidence($html);

    expect($derived['proposals'])->not->toHaveKey('corners');
});

it('reads generous padding as spacious and tight padding as default', function () {
    $airy = '<style>.a{padding:48px}.b{padding:2rem}.c{margin:40px}.d{padding:32px}.e{margin:3rem}</style>';
    $dense = '<style>.a{padding:8px}.b{padding:4px}.c{margin:8px}.d{padding:12px}.e{margin:6px}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($airy)['proposals']['spacing'] ?? null)
        ->toBe('spacious')
        ->and(app(DesignKitAutopilot::class)->fromWebsiteEvidence($dense)['proposals']['spacing'] ?? null)
        ->toBe('default');
});

it('ignores scroll-padding / scroll-margin / vendor-prefixed lookalikes in the density read', function () {
    // The critic's find: without a declaration-start anchor, scroll-snap
    // resets crossed the evidence floor on bogus data behind a PERMANENT
    // fill-if-empty write.
    $html = '<style>.a{scroll-padding:48px}.b{scroll-padding-top:40px}.c{scroll-margin:44px}'
        .'.d{-webkit-padding-start:52px}.e{scroll-margin-top:56px}.real{padding:8px}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals'])->not->toHaveKey('spacing');
});

it('proposes no spacing below the evidence floor (fewer than 5 declarations)', function () {
    $html = '<style>.a{padding:48px}.b{padding:40px}</style>';

    expect(app(DesignKitAutopilot::class)->fromWebsiteEvidence($html)['proposals'])->not->toHaveKey('spacing');
});

it('persists corners and spacing fill-if-empty but never clobbers a manual pick', function () {
    $siteId = autopilotSite();
    DB::connection('pgsql')->table('site.design_kits')->updateOrInsert(['site_id' => $siteId], ['corners' => 'rounded']);

    $wrote = app(DesignKitAutopilot::class)->persistFillIfEmpty($siteId, [
        'corners' => 'sharp',    // manual 'rounded' stands
        'spacing' => 'spacious', // NULL → written
    ]);

    $kit = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $siteId)->first();
    expect($wrote)->toBe(['spacing'])
        ->and($kit->corners)->toBe('rounded')
        ->and($kit->spacing)->toBe('spacious');
});

// ── the gallery palette fallback (plan 02 step 5, decision 3) ────────────────

it('the gallery fallback reads POOL_GALLERY only — synced content imagery is not an accent source', function () {
    // Owner exclusion: Instagram imagery must never feed the accent;
    // platform-synced media lives in the content pool.
    $siteId = autopilotSite();
    DB::connection('pgsql')->table('site.site_media')->where('site_id', $siteId)->delete();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'site_id' => $siteId, 'pool' => 'content', 'purpose' => null,
        'processing_state' => 'ready',
        'palette' => json_encode(['dominant' => '#e0491f', 'colors' => ['#e0491f'], 'warm' => true]),
        'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(),
    ]);

    expect(app(DesignKitAutopilot::class)->fromBrandPalette($siteId)['reason'])
        ->toBe(DesignKitAutopilot::REASON_NO_PALETTE);
});

it('a neutral GALLERY palette reports neutral_palette, not the wordmark copy', function () {
    // The wordmark reason would be a lie for a site with no wordmark at
    // all (critic find): the reason names the actual source now.
    $siteId = autopilotSite();
    DB::connection('pgsql')->table('site.site_media')->where('site_id', $siteId)->delete();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'site_id' => $siteId, 'pool' => 'gallery', 'purpose' => null,
        'processing_state' => 'ready',
        'palette' => json_encode(['dominant' => '#111111', 'colors' => ['#111111', '#fafafa'], 'warm' => false]),
        'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(),
    ]);

    expect(app(DesignKitAutopilot::class)->fromBrandPalette($siteId)['reason'])
        ->toBe(DesignKitAutopilot::REASON_NEUTRAL_PALETTE);
});

it('falls back to the oldest ready gallery image palette when no logo palette exists', function () {
    $siteId = autopilotSite();
    // Blank the logo palettes; give the site one gallery image with a
    // strong brand colour.
    DB::connection('pgsql')->table('site.site_media')->where('site_id', $siteId)->delete();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'site_id' => $siteId, 'pool' => 'gallery', 'purpose' => null,
        'processing_state' => 'ready',
        'palette' => json_encode(['dominant' => '#0f766e', 'colors' => ['#0f766e'], 'warm' => false]),
        'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(),
    ]);

    $derived = app(DesignKitAutopilot::class)->fromBrandPalette($siteId);

    expect($derived['reason'])->toBeNull()
        ->and($derived['proposals']['color_accent'] ?? null)->not->toBeNull();
});

// ── persistFillIfEmpty ───────────────────────────────────────────────────────

it('fills only the columns that are empty', function () {
    $siteId = autopilotSite();
    DB::table('site.design_kits')->insert([
        'site_id' => $siteId, 'color_accent' => '#123456',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $wrote = app(DesignKitAutopilot::class)->persistFillIfEmpty($siteId, [
        'color_accent' => '#e0491f',
        'typography_font_family' => 'inter',
    ]);

    $kit = DB::connection('pgsql')->table('site.design_kits')->where('site_id', $siteId)->first();
    expect($wrote)->toBe(['typography_font_family'])
        ->and($kit->color_accent)->toBe('#123456')
        ->and($kit->typography_font_family)->toBe('inter');
});

it('never writes a column outside the autopilot allowlist', function () {
    $siteId = autopilotSite();

    $wrote = app(DesignKitAutopilot::class)->persistFillIfEmpty($siteId, [
        'text_size' => 'large',
        'site_id' => 'evil',
    ]);

    expect($wrote)->toBe([]);
});

it('marks the document stale when it writes', function () {
    $siteId = autopilotSite();

    app(DesignKitAutopilot::class)->persistFillIfEmpty($siteId, ['color_accent' => '#e0491f']);

    expect((int) DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBeGreaterThan(0);
});

// ── WcagContrast (the real math) ─────────────────────────────────────────────

it('computes the canonical 21:1 black-on-white ratio', function () {
    expect(round(WcagContrast::ratio('#000000', '#ffffff'), 1))->toBe(21.0)
        ->and(round(WcagContrast::ratio('#ffffff', '#000000'), 1))->toBe(21.0)
        ->and(WcagContrast::ratio('#808080', '#808080'))->toBe(1.0);
});
