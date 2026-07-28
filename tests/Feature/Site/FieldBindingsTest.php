<?php

// The §14 field-bindings substrate (WAVE-2C item 5): preset seeding + the
// resolution path. What these pin, in order of importance:
//   - the two gates fail CLOSED (no binding / disabled binding / lock row)
//   - the IdentitySync law survives translation (business overwrites,
//     partna fills blanks)
//   - the sector law is carried verbatim (manual permanent; first non-Google
//     wins; Google may replace only its own stamp)

use App\Models\Core\User\User;
use App\Services\Profile\FieldBindingResolver;
use App\Services\Profile\FieldBindingSeeder;
use App\Site\Presets\PresetInstantiator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupFieldBindingsTable();
    setupSectionsTables();
});

function bindingsFor(User $pro): array
{
    return DB::table('site.field_bindings')
        ->where('site_id', $pro->site->id)
        ->get()
        ->groupBy('field')
        ->toArray();
}

function manualLock(User $pro, string $field): void
{
    DB::table('site.field_bindings')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $pro->site->id,
        'field' => $field,
        'source_key' => 'manual',
        'priority' => 0,
        'mode' => 'overwrite',
        'is_enabled' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ── Seeding ──────────────────────────────────────────────────────────────────

it('seeds overwrite standing for a business account', function () {
    $pro = createTenant('fb-biz', ['account_type' => 'business']);

    app(FieldBindingSeeder::class)->seed($pro->site);

    $row = DB::table('site.field_bindings')
        ->where('site_id', $pro->site->id)->where('field', 'name')->first();
    expect($row->source_key)->toBe('google_business')
        ->and($row->mode)->toBe('overwrite')
        ->and((int) $row->priority)->toBeGreaterThan(0);
});

it('seeds fill-blank standing for a partna account', function () {
    $pro = createTenant('fb-partna', ['account_type' => 'partna']);

    app(FieldBindingSeeder::class)->seed($pro->site);

    expect(DB::table('site.field_bindings')->where('site_id', $pro->site->id)->pluck('mode')->unique()->all())
        ->toBe(['fill_blank']);
});

it('keeps a user\'s disable through a re-seed', function () {
    $pro = createTenant('fb-reseed', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);

    DB::table('site.field_bindings')->where('site_id', $pro->site->id)
        ->where('field', 'phone')->update(['is_enabled' => 0]);

    app(FieldBindingSeeder::class)->seed($pro->site);

    expect((bool) DB::table('site.field_bindings')->where('site_id', $pro->site->id)
        ->where('field', 'phone')->value('is_enabled'))->toBeFalse()
        ->and(DB::table('site.field_bindings')->where('site_id', $pro->site->id)->count())
        ->toBe(count(FieldBindingSeeder::FIELDS));
});

it('rides preset instantiation', function () {
    $pro = createTenant('fb-preset', ['sector' => 'cafe', 'account_type' => 'business']);

    app(PresetInstantiator::class)->instantiate($pro->site);

    expect(DB::table('site.field_bindings')->where('site_id', $pro->site->id)->count())
        ->toBe(count(FieldBindingSeeder::FIELDS));
});

// ── The resolution path: the law, translated ─────────────────────────────────

it('lets an overwrite binding replace a stored value (business law)', function () {
    $pro = createTenant('fb-ow', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);

    $resolver = app(FieldBindingResolver::class);
    $resolver->apply($pro, 'google_business', ['name' => 'Old Name']);
    $written = $resolver->apply($pro, 'google_business', ['name' => 'New Name']);

    expect($written)->toBe(['name'])
        ->and(DB::table('site.workplaces')->where('site_id', $pro->site->id)->value('name'))->toBe('New Name');
});

it('lets a fill-blank binding fill only what is empty (partna law)', function () {
    $pro = createTenant('fb-fill', ['account_type' => 'partna']);
    app(FieldBindingSeeder::class)->seed($pro->site);

    DB::table('site.workplaces')->insert([
        'site_id' => $pro->site->id, 'name' => 'Hand Typed',
        'field_sources' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $written = app(FieldBindingResolver::class)->apply($pro, 'google_business', [
        'name' => 'Google Name',
        'phone' => '+61 400 000 000',
    ]);

    $workplace = DB::table('site.workplaces')->where('site_id', $pro->site->id)->first();
    expect($written)->toBe(['phone'])
        ->and($workplace->name)->toBe('Hand Typed')
        ->and($workplace->phone)->toBe('+61 400 000 000');
});

it('stamps provenance for every field it writes', function () {
    $pro = createTenant('fb-prov', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);

    app(FieldBindingResolver::class)->apply($pro, 'google_business', ['website' => 'https://example.com']);

    $sources = json_decode((string) DB::table('site.workplaces')->where('site_id', $pro->site->id)->value('field_sources'), true);
    expect($sources['website']['source'])->toBe('google_business');
});

// ── Gate 1: platform-side ────────────────────────────────────────────────────

it('writes nothing without a binding row — fail closed', function () {
    $pro = createTenant('fb-norow', ['account_type' => 'business']);

    $written = app(FieldBindingResolver::class)->apply($pro, 'google_business', ['name' => 'Google Name']);

    expect($written)->toBe([])
        ->and(DB::table('site.workplaces')->where('site_id', $pro->site->id)->exists())->toBeFalse();
});

it('respects a disabled binding', function () {
    $pro = createTenant('fb-disabled', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);
    DB::table('site.field_bindings')->where('site_id', $pro->site->id)
        ->where('field', 'name')->update(['is_enabled' => 0]);

    $written = app(FieldBindingResolver::class)->apply($pro, 'google_business', ['name' => 'Google Name']);

    expect($written)->toBe([]);
});

// ── Gate 2: field-side lock ──────────────────────────────────────────────────

it('never touches a field with a manual lock row, even for overwrite', function () {
    $pro = createTenant('fb-locked', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);
    manualLock($pro, 'name');

    DB::table('site.workplaces')->insert([
        'site_id' => $pro->site->id, 'name' => 'The User Wrote This',
        'field_sources' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $written = app(FieldBindingResolver::class)->apply($pro, 'google_business', ['name' => 'Google Name']);

    expect($written)->toBe([])
        ->and(DB::table('site.workplaces')->where('site_id', $pro->site->id)->value('name'))
        ->toBe('The User Wrote This');
});

// ── Priority between platforms ───────────────────────────────────────────────

it('does not let a lower-precedence source displace a higher one', function () {
    $pro = createTenant('fb-prio', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site); // google_business at 10

    DB::table('site.field_bindings')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $pro->site->id,
        'field' => 'name',
        'source_key' => 'instagram',
        'priority' => 50,
        'mode' => 'overwrite',
        'is_enabled' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $resolver = app(FieldBindingResolver::class);
    $resolver->apply($pro, 'google_business', ['name' => 'Google Name']);
    $written = $resolver->apply($pro, 'instagram', ['name' => 'IG Name']);

    expect($written)->toBe([])
        ->and(DB::table('site.workplaces')->where('site_id', $pro->site->id)->value('name'))->toBe('Google Name');
});

it('reads legacy hyphenated provenance as the same source', function () {
    // Pre-§4 stamps say 'google-business'; the binding says 'google_business'.
    // Same source — Google may still replace its own stamp.
    $pro = createTenant('fb-legacy', ['account_type' => 'business']);
    app(FieldBindingSeeder::class)->seed($pro->site);

    DB::table('site.workplaces')->insert([
        'site_id' => $pro->site->id, 'name' => 'Old Google Name',
        'field_sources' => json_encode(['name' => ['source' => 'google-business', 'at' => now()->toIso8601String()]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $written = app(FieldBindingResolver::class)->apply($pro, 'google_business', ['name' => 'New Google Name']);

    expect($written)->toBe(['name']);
});

// ── The sector law, verbatim ─────────────────────────────────────────────────

it('never lets Google overwrite a manually picked sector', function () {
    $pro = createTenant('fb-sector-manual', ['account_type' => 'business', 'sector' => 'barber', 'sector_source' => 'manual']);

    app(FieldBindingResolver::class)->applySector($pro, 'google_business', 'cafe', 'overwrite');

    expect($pro->fresh()->sector)->toBe('barber');
});

it('lets the first non-Google source win permanently', function () {
    $pro = createTenant('fb-sector-ig', ['account_type' => 'business', 'sector' => 'restaurant', 'sector_source' => 'instagram']);

    app(FieldBindingResolver::class)->applySector($pro, 'google_business', 'cafe', 'overwrite');

    expect($pro->fresh()->sector)->toBe('restaurant');
});

it('lets Google replace a sector it stamped itself', function () {
    $pro = createTenant('fb-sector-self', ['account_type' => 'business', 'sector' => 'cafe', 'sector_source' => 'google_business']);

    app(FieldBindingResolver::class)->applySector($pro, 'google_business', 'restaurant', 'overwrite');

    expect($pro->fresh()->sector)->toBe('restaurant');
});

it('fills a blank sector under fill-blank standing', function () {
    $pro = createTenant('fb-sector-fill', ['account_type' => 'partna']);

    app(FieldBindingResolver::class)->applySector($pro, 'google_business', 'cafe', 'fill_blank');

    expect($pro->fresh()->sector)->toBe('cafe');
});

it('never clobbers an existing sector under fill-blank standing', function () {
    $pro = createTenant('fb-sector-keep', ['account_type' => 'partna', 'sector' => 'barber', 'sector_source' => 'google_business']);

    app(FieldBindingResolver::class)->applySector($pro, 'google_business', 'cafe', 'fill_blank');

    expect($pro->fresh()->sector)->toBe('barber');
});
