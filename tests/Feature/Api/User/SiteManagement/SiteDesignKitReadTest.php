<?php

// I1/I7: GET/PATCH /api/site must return the preset-merged effective
// design_kit (SiteResource::withResolvedDesignKit), not the raw, preset-blind
// stored partial — the dashboard design editor's only read surface.

use App\Models\Core\User\User;
use App\Services\Design\SectorStylePresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupDesignKitsTable();
});

function seedSectorOwner(string $subdomain, ?string $sector): User
{
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId, 'handle' => $subdomain, 'handle_lc' => strtolower($subdomain),
        'display_name' => 'Sector Pro', 'first_name' => 'Sector Pro', 'primary_email' => $subdomain.'@example.com',
        'status' => 'active', 'sector' => $sector, 'sector_source' => $sector ? 'manual' : null,
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'subdomain' => $subdomain, 'is_published' => 1,
    ]);

    return User::query()->findOrFail($userId);
}

it('GET /api/site returns the preset-merged kit for a restaurant with only accent set', function () {
    $owner = seedSectorOwner('resttest', 'restaurant');
    DB::connection('pgsql')->table('site.design_kits')
        ->insert(['site_id' => $owner->site->id, 'color_accent' => '#105030']);

    // The food_drink bucket used to also set weight_regular '300'. That column
    // left the schema with the 2026-08-09 preset-only migration, and the owner
    // decision was to let the gutted sectors collapse rather than invent new
    // differentiation — so a sector preset is accent-plus-font now. The FONT
    // still arrives from the preset while the manual accent wins over it,
    // which is the whole point of this test.
    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.design_kit.typography_font_family', 'monument-grotesk')
        ->assertJsonPath('site.design_kit.color_accent', '#105030')
        ->assertJsonPath('site.design_kit_manual', ['color_accent'])
        // the endpoint's existing chain (withRationale/withFeatureAvailability)
        // must still be intact alongside the new resolved-kit addition
        ->assertJsonStructure(['site' => ['design_rationale', 'feature_availability']]);
});

it('GET /api/site reports no manual columns and an empty kit for a sectorless owner', function () {
    $owner = seedSectorOwner('nosectortest', null);

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.design_kit_manual', [])
        ->assertJson(fn ($json) => $json->where('site.design_kit', [])->etc());
});

it('PATCH /api/site also returns the resolved kit on the same round-trip', function () {
    config(['partna.throttle.enabled' => false]);
    $owner = seedSectorOwner('patchresolved', 'restaurant');
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $owner->site->id]);

    // A non-design-kit update (subdomain unchanged) still round-trips a
    // resolved kit — update()'s resource chain must carry the same opt-in
    // as show(), not just the read endpoint.
    actingAsUser($owner)
        ->patchJson('/api/site', ['settings' => ['show_branding' => false]])
        ->assertOk()
        ->assertJsonPath('site.design_kit.typography_font_family', 'monument-grotesk')
        ->assertJsonPath('site.design_kit_manual', []);
});

it('a different sector bucket resolves correctly over HTTP too', function () {
    $owner = seedSectorOwner('plumbertest', 'plumber');

    actingAsUser($owner)
        ->getJson('/api/site')
        ->assertOk()
        // A plumber reads masculine since 2026-09-02: NB Architekt, the neon.
        ->assertJsonPath('site.design_kit.typography_font_family', 'nb-architekt')
        ->assertJsonPath('site.design_kit.color_accent', SectorStylePresets::MASCULINE_ACCENT)
        ->assertJsonPath('site.design_kit_manual', []);
});
