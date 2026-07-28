<?php

// The §15 preset library + instantiator (WAVE-2C item 4): a cold build seeds
// pages/sections from f(AccountCapabilities, bucket [, slug]) and NOTHING is
// ever destructive — refinement adds, skips, and leaves the user's own
// arrangement alone.

use App\Models\Core\User\User;
use App\Site\Documents\DocumentBuilder;
use App\Site\Presets\PresetInstantiator;
use App\Site\Presets\PresetLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // The full content plane: the end-to-end case runs the DocumentBuilder,
    // whose facet rules EXISTS-join the typed tables (f_action et al).
    setupContentTables();
});

function presetTenant(string $handle, array $overrides = []): User
{
    return createTenant($handle, $overrides);
}

function instantiateFor(User $pro): array
{
    return app(PresetInstantiator::class)->instantiate($pro->site);
}

function pageKeys(string $siteId): array
{
    return DB::table('site.pages')->where('site_id', $siteId)->orderBy('sort_order')->pluck('key')->all();
}

// ── The library's resolution ─────────────────────────────────────────────────

it('resolves the preset from bucket, sharpened by slug', function () {
    expect(PresetLibrary::forUser(presetTenant('preset-barber', ['sector' => 'barber'])))->toBe('hair_beauty')
        ->and(PresetLibrary::forUser(presetTenant('preset-cafe', ['sector' => 'cafe'])))->toBe('food_drink')
        ->and(PresetLibrary::forUser(presetTenant('preset-muso', ['sector' => 'musician'])))->toBe('music_audio')
        ->and(PresetLibrary::forUser(presetTenant('preset-cc', ['sector' => 'content-creator'])))->toBe('video_creator')
        ->and(PresetLibrary::forUser(presetTenant('preset-photog', ['sector' => 'photographer'])))->toBe('general')
        ->and(PresetLibrary::forUser(presetTenant('preset-none')))->toBe('general');
});

it('gives every preset Home, Contact and Links', function () {
    foreach (['hair_beauty', 'food_drink', 'music_audio', 'video_creator', 'fitness_wellbeing', 'events_nightlife', 'trades_services', 'retail_commerce', 'education_coaching', 'general'] as $preset) {
        $keys = array_column(PresetLibrary::pages($preset), 'key');
        expect($keys)->toContain('home')
            ->and($keys)->toContain('contact')
            ->and($keys)->toContain('links');
    }
});

// ── Instantiation ────────────────────────────────────────────────────────────

it('seeds the hair_beauty arrangement for a barber', function () {
    $pro = presetTenant('inst-barber', ['sector' => 'barber']);

    $result = instantiateFor($pro);

    expect($result['preset'])->toBe('hair_beauty')
        ->and(pageKeys($pro->site->id))->toBe(['home', 'services', 'photos', 'reviews', 'contact', 'links']);

    $actions = DB::table('site.sections')->where('site_id', $pro->site->id)->where('key', 'home.actions')->first();
    expect($actions->slot)->toBe('header_actions')
        ->and((int) $actions->limit_n)->toBe(6);
});

it('withholds the Menu page from an account without the menu capability', function () {
    // A partna cafe is food-sector but menu is a business capability — the
    // instantiator must not mint what a write request would refuse.
    $pro = presetTenant('inst-partna-cafe', ['sector' => 'cafe', 'account_type' => 'partna']);

    instantiateFor($pro);

    expect(pageKeys($pro->site->id))->not->toContain('menu');
});

it('creates the Menu page for a business cafe', function () {
    $pro = presetTenant('inst-biz-cafe', ['sector' => 'cafe', 'account_type' => 'business']);

    instantiateFor($pro);

    $menu = DB::table('site.pages')->where('site_id', $pro->site->id)->where('key', 'menu')->first();
    expect($menu)->not->toBeNull()
        ->and($menu->capability)->toBe('menu');

    $section = DB::table('site.sections')->where('site_id', $pro->site->id)->where('key', 'menu.items')->first();
    expect($section->group_by)->toBe('category');
});

it('is idempotent — a second run adds nothing', function () {
    $pro = presetTenant('inst-idem', ['sector' => 'barber']);

    instantiateFor($pro);
    $second = instantiateFor($pro);

    expect($second['createdPages'])->toBe([])
        ->and($second['createdSections'])->toBe([])
        ->and(DB::table('site.pages')->where('site_id', $pro->site->id)->count())->toBe(6);
});

it('refines additively when the sector lands later', function () {
    // Cold build with no sector → general (home/photos/contact/links). The
    // user then picks barber; the re-run ADDS services + reviews and touches
    // nothing that exists.
    $pro = presetTenant('inst-refine');
    instantiateFor($pro);
    expect(pageKeys($pro->site->id))->toBe(['home', 'photos', 'contact', 'links']);

    DB::table('core.users')->where('id', $pro->id)->update(['sector' => 'barber', 'sector_source' => 'manual']);
    $pro->refresh();

    $result = instantiateFor($pro);

    expect($result['createdPages'])->toBe(['services', 'reviews'])
        ->and(count(pageKeys($pro->site->id)))->toBe(6);
});

it('never touches a page the user made themselves', function () {
    $pro = presetTenant('inst-usermade', ['sector' => 'barber']);

    // A user-created page (no preset lineage) squatting on a preset key.
    DB::table('site.pages')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $pro->site->id,
        'key' => 'services', 'label' => 'My Own Services', 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    instantiateFor($pro);

    $page = DB::table('site.pages')->where('site_id', $pro->site->id)->where('key', 'services')->first();
    expect($page->label)->toBe('My Own Services')
        ->and($page->preset_key)->toBeNull()
        // And no preset section was smuggled onto their page.
        ->and(DB::table('site.sections')->where('site_id', $pro->site->id)->where('key', 'services.book')->count())->toBe(0);
});

it('marks the document stale after seeding', function () {
    $pro = presetTenant('inst-bump', ['sector' => 'cafe']);

    instantiateFor($pro);

    expect((int) DB::table('site.site_build_state')->where('site_id', $pro->site->id)->value('content_revision'))
        ->toBeGreaterThan(0);
});

it('writes rules the document builder can execute', function () {
    // The arrangement is only real if the builder resolves it: a media item
    // must land in the photos grid of a freshly seeded site.
    $pro = presetTenant('inst-endtoend', ['sector' => 'photographer']);
    instantiateFor($pro);

    DB::table('content.items')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $pro->id, 'kind' => 'media',
        'headline_cache' => 'A Photo', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = (new DocumentBuilder)->build((string) $pro->site->id);
    $document = json_decode((string) DB::table('site.site_documents')->value('document'), true);

    $photos = collect($document['pages'])->firstWhere('key', 'photos');
    expect($result['status'])->toBe('built')
        ->and($photos['sections'][0]['items'][0]['headline'])->toBe('A Photo');
});
