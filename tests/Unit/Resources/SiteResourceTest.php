<?php

use App\Http\Resources\SiteResource;
use App\Models\Core\Site\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('ships only the allowlisted columns and passes non-design settings through', function () {
    $site = new Site([
        'subdomain' => 'example',
        'architecture_id' => 'staple',
        'is_published' => true,
        'unpublished_at' => null,
        'settings' => [
            'booking_mode' => 'manual',
        ],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';
    $site->setAttribute('user_id', '99999999-9999-9999-9999-999999999999');
    $site->created_at = Carbon::parse('2026-01-01T00:00:00Z');
    $site->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
    $site->subdomain_changed_at = null;
    $site->setAttribute('internal_flag', 'top-secret');

    $array = (new SiteResource($site))->resolve();

    // booking_mode is promoted to a top-level key when present in settings
    // (API-1) so the dashboard booking editor and the dedicated
    // updateBookingSettings endpoint share one response shape.
    // design_rationale is opt-in (withRationale) — NOT present by default.
    expect(array_keys($array))->toEqual([
        'id', 'user_id', 'subdomain', 'architecture_id', 'is_published',
        'subdomain_changed_at', 'unpublished_at', 'settings', 'design_kit',
        'created_at', 'updated_at', 'booking_mode',
    ]);
    expect($array)->not->toHaveKey('design_rationale');
    expect($array)->not->toHaveKey('internal_flag');
    expect($array)->not->toHaveKey('theme_id');
    expect($array['id'])->toBeString();
    expect($array['architecture_id'])->toBe('staple');
    expect($array['booking_mode'])->toBe('manual');
    expect($array['settings'])->toBeInstanceOf(stdClass::class);
    // PHP (object) cast only wraps the top level — nested arrays stay arrays.
    expect($array['settings']->booking_mode)->toBe('manual');
});

it('includes a stable-shaped design_rationale only when opted in', function () {
    $site = new Site([
        'subdomain' => 'example',
        'architecture_id' => 'staple',
        'is_published' => true,
        'settings' => [],
    ]);
    $site->id = '44444444-4444-4444-4444-444444444444';

    $array = (new SiteResource($site))->withRationale()->resolve();

    // Present with a stable shape (fails closed to an empty rationale when the
    // contribution/kit tables aren't readable, e.g. the SQLite unit mirror).
    expect($array)->toHaveKey('design_rationale');
    expect($array['design_rationale'])->toHaveKeys(['summary', 'hasOverrides', 'items'])
        ->and($array['design_rationale']['hasOverrides'])->toBeBool()
        ->and($array['design_rationale']['items'])->toBeArray();
});

it('handles empty settings as {} not []', function () {
    $site = new Site([
        'subdomain' => 'example',
        'architecture_id' => 'staple',
        'is_published' => false,
        'settings' => [],
    ]);
    $site->id = '11111111-1111-1111-1111-111111111111';

    $array = (new SiteResource($site))->resolve();

    expect($array['settings'])->toBeInstanceOf(stdClass::class);
    // booking_mode / manual_booking_url are omitted (not null) when absent from settings.
    expect($array)->not->toHaveKey('booking_mode');
    expect($array)->not->toHaveKey('manual_booking_url');
});

it('builds settings.booking_mode + top-level booking_mode from the promoted column', function () {
    // FOUND-16: column is the source of truth; settings JSONB is empty (post-strip shape).
    $site = new Site([
        'subdomain' => 'example',
        'architecture_id' => 'staple',
        'is_published' => true,
        'settings' => [],
    ]);
    $site->id = '22222222-2222-2222-2222-222222222222';
    // Simulate columns populated by Phase 1 (no JSONB fallback needed).
    $site->booking_mode = 'none';
    $site->show_branding = false;

    $array = (new SiteResource($site))->resolve();

    expect($array['settings']->booking_mode)->toBe('none')
        ->and($array['settings']->show_branding)->toBeFalse()
        ->and($array['booking_mode'])->toBe('none')
        ->and($array)->not->toHaveKey('manual_booking_url');
});

it('promoted columns win over residual JSONB value during dual-write', function () {
    // Both the column and the JSONB carry a value; the column must win.
    $site = new Site([
        'subdomain' => 'example',
        'architecture_id' => 'staple',
        'is_published' => true,
        'settings' => ['booking_mode' => 'manual'],
    ]);
    $site->id = '33333333-3333-3333-3333-333333333333';
    $site->booking_mode = 'none';

    $array = (new SiteResource($site))->resolve();

    expect($array['booking_mode'])->toBe('none')
        ->and($array['settings']->booking_mode)->toBe('none');
});

// I1/I4: withResolvedDesignKit() overlays ProfileDesignPresets under the
// site's manually-set site.design_kits columns (manual wins per column) and
// reports which columns are manual. Uses the same SQLite-mirror helpers as
// DesignRationaleServiceTest (setupUsersTable/setupSitesTable/
// setupDesignKitsTable + createTenant) rather than a real Postgres
// connection — the mirror already covers every column these tests touch.
describe('withResolvedDesignKit', function () {
    beforeEach(function () {
        setupUsersTable();
        setupSitesTable();
        setupDesignKitsTable();
    });

    it('merges the sector preset under a manual column, manual wins', function () {
        $user = createTenant('resolved-restaurant');
        $user->sector = 'restaurant'; // food_drink bucket, no slug refinement
        $user->sector_source = 'manual';
        $user->save();
        DB::connection('pgsql')->table('site.design_kits')->insert([
            'site_id' => $user->site->id,
            'color_accent' => '#105030',
        ]);

        $array = (new SiteResource($user->site->fresh()))
            ->withResolvedDesignKit($user)
            ->resolve();

        // preset values show through untouched
        expect($array['design_kit']->typography_font_family)->toBe('general-sans')
            ->and($array['design_kit']->weight_regular)->toBe('300')
            ->and($array['design_kit']->motion_pace)->toBe('fast')
            ->and($array['design_kit']->effect_image_treatment)->toBe('warm')
            // manual column wins over the preset's own accent (#e0491f)
            ->and($array['design_kit']->color_accent)->toBe('#105030')
            // only the stored column is reported manual
            ->and($array['design_kit_manual'])->toBe(['color_accent']);
    });

    it('emits only raw stored columns and no manual marker when NOT opted in', function () {
        $user = createTenant('resolved-optout');
        $user->sector = 'restaurant';
        $user->save();
        DB::connection('pgsql')->table('site.design_kits')->insert([
            'site_id' => $user->site->id,
            'color_accent' => '#105030',
        ]);

        $array = (new SiteResource($user->site->fresh()))->resolve();

        expect((array) $array['design_kit'])->toBe(['color_accent' => '#105030']);
        expect($array)->not->toHaveKey('design_kit_manual');
    });

    it('falls back to the empty preset with no manual columns when the user has no sector', function () {
        $user = createTenant('resolved-nosector');
        // sector left null — ProfileDesignPresets::forUser() returns [] for this.

        $array = (new SiteResource($user->site->fresh()))
            ->withResolvedDesignKit($user)
            ->resolve();

        expect((array) $array['design_kit'])->toBe([]);
        expect($array['design_kit_manual'])->toBe([]);
    });

    it('applies a different bucket correctly with zero manual overrides', function () {
        $user = createTenant('resolved-plumber');
        $user->sector = 'plumber'; // home_services bucket, refined (color_accent only)
        $user->save();
        // No site.design_kits row inserted at all for this site.

        $array = (new SiteResource($user->site->fresh()))
            ->withResolvedDesignKit($user)
            ->resolve();

        expect($array['design_kit']->typography_font_family)->toBe('forma-djr')
            ->and($array['design_kit']->weight_regular)->toBe('500')
            ->and($array['design_kit']->text_body)->toBe('0.8125rem')
            // slug refinement (plumber) wins over the home_services bucket's own accent
            ->and($array['design_kit']->color_accent)->toBe('#0369a1')
            ->and($array['design_kit_manual'])->toBe([]);
    });
});
