<?php

// Central-identity precedence engine (IdentitySync) + the manual workplace
// upsert's provenance/mirror behaviour. Exercises the account-type split
// through AccountCapabilities::google_business_full_sync — business overwrites,
// partna fills gaps — plus the "email never from Google" and phone-mirror rules.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also sets up site.workplaces + site.platform_connections
    // The manual upsert re-evaluates the 'workplace' section's visibility, which
    // queries site.blocks — needed even though no block row is seeded.
    setupBlocksTable();
    AccountCapabilities::flushCache();
});

function idsyncUser(string $handle, string $accountType): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => $accountType,
        'status' => 'active',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function idsyncSite(User $user): string
{
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 0,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $siteId;
}

/**
 * A Place Details (New) response with the identity fields IdentitySync reads:
 * name, address, phone, website, category, location, and structured hours.
 */
function idsyncPlaceDetailsResponse(): array
{
    return [
        'id' => 'ChIJidsync',
        'displayName' => ['text' => 'Fade Lab Barbers'],
        'formattedAddress' => '12 Example St, Melbourne VIC 3000',
        'location' => ['latitude' => -37.8123, 'longitude' => 144.9601],
        'googleMapsUri' => 'https://maps.google.com/?cid=999',
        'businessStatus' => 'OPERATIONAL',
        'primaryTypeDisplayName' => ['text' => 'Barber shop'],
        'nationalPhoneNumber' => '(03) 9123 4567',
        'websiteUri' => 'https://fadelab.example',
        'utcOffsetMinutes' => 600,
        'regularOpeningHours' => [
            'weekdayDescriptions' => ['Monday: 9:00 AM – 5:00 PM'],
            'periods' => [
                ['open' => ['day' => 1, 'hour' => 9, 'minute' => 0], 'close' => ['day' => 1, 'hour' => 17, 'minute' => 30]],
                ['open' => ['day' => 2, 'hour' => 10, 'minute' => 0], 'close' => ['day' => 2, 'hour' => 18, 'minute' => 0]],
            ],
        ],
    ];
}

/** Fake the Places details + media + street view endpoints for a picker connect. */
function idsyncFakePlaces(): void
{
    config(['services.google_maps.server_api_key' => 'server-key']);
    config(['services.apify.token' => null]); // no async enrichment in these tests
    Http::fake([
        'places.googleapis.com/v1/places/*/photos/*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg']),
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response(idsyncPlaceDetailsResponse()),
    ]);
}

// ── (a) Business connect overwrites manual values + sets hours + sector ──────

it('business google connect overwrites manual name/phone, sets hours + sector, stamps google source', function () {
    idsyncFakePlaces();
    $user = idsyncUser('bizsync', 'business');
    $siteId = idsyncSite($user);

    // Pre-existing MANUAL workplace values that the business sync must overwrite.
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Old Manual Name',
        'phone' => '(03) 0000 0000',
        'contact_email' => 'manual@example.com',
        'field_sources' => json_encode([
            'name' => ['source' => 'manual', 'at' => '2026-01-01T00:00:00+00:00'],
            'phone' => ['source' => 'manual', 'at' => '2026-01-01T00:00:00+00:00'],
            'contact_email' => ['source' => 'manual', 'at' => '2026-01-01T00:00:00+00:00'],
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();

    // Overwritten from Google.
    // Place Details' displayName ("Fade Lab Barbers", 16 chars) is over the
    // 15-char business-name cap — IdentitySync word-trims it to "Fade Lab".
    expect($workplace->name)->toBe('Fade Lab');
    expect($workplace->phone)->toBe('(03) 9123 4567');
    expect($workplace->website)->toBe('https://fadelab.example');
    expect($workplace->address)->toBe('12 Example St, Melbourne VIC 3000');
    expect((float) $workplace->latitude)->toBe(-37.8123);

    // Structured hours derived from periods (day 1 = mon, day 2 = tue).
    expect($workplace->opening_hours['mon'])->toBe([['open' => '0900', 'close' => '1730']]);
    expect($workplace->opening_hours['tue'])->toBe([['open' => '1000', 'close' => '1800']]);

    // field_sources flipped to google-business for the written fields…
    expect($workplace->field_sources['name']['source'])->toBe('google-business');
    expect($workplace->field_sources['phone']['source'])->toBe('google-business');
    expect($workplace->field_sources['opening_hours']['source'])->toBe('google-business');
    // …but the untouched email keeps its manual provenance AND value.
    expect($workplace->contact_email)->toBe('manual@example.com');
    expect($workplace->field_sources['contact_email']['source'])->toBe('manual');

    // Sector mapped from "Barber shop" → 'barber', stamped google-business.
    $user->refresh();
    expect($user->sector)->toBe('barber');
    expect($user->sector_source)->toBe('google-business');

    // Phone mirrored to the user's public contact number.
    expect($user->public_contact_number)->toBe('(03) 9123 4567');
});

// ── (b) Partna connect fills blanks only, never clobbers manual values ───────

it('partna google connect only fills blank fields and never clobbers manual values', function () {
    idsyncFakePlaces();
    $user = idsyncUser('partnasync', 'partna');
    // A manually-set public contact number the partna mirror must PRESERVE
    // (fills only when blank). Proves the phone mirror honours the same rule.
    $user->forceFill(['public_contact_number' => '(03) 1111 1111'])->save();
    $siteId = idsyncSite($user);

    // Manual name + phone the partna sync must PRESERVE; website is blank so it fills.
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'My Chosen Name',
        'phone' => '(03) 1111 1111',
        'field_sources' => json_encode([
            'name' => ['source' => 'manual', 'at' => '2026-01-01T00:00:00+00:00'],
            'phone' => ['source' => 'manual', 'at' => '2026-01-01T00:00:00+00:00'],
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();

    // Manual values untouched — still manual in both value and provenance.
    expect($workplace->name)->toBe('My Chosen Name');
    expect($workplace->phone)->toBe('(03) 1111 1111');
    expect($workplace->field_sources['name']['source'])->toBe('manual');
    expect($workplace->field_sources['phone']['source'])->toBe('manual');

    // Blank fields FILLED from Google, stamped google-business.
    expect($workplace->website)->toBe('https://fadelab.example');
    expect($workplace->address)->toBe('12 Example St, Melbourne VIC 3000');
    expect($workplace->field_sources['website']['source'])->toBe('google-business');
    expect($workplace->opening_hours['mon'])->toBe([['open' => '0900', 'close' => '1730']]);

    // Sector was blank → filled. Phone was manual → NOT mirrored over.
    $user->refresh();
    expect($user->sector)->toBe('barber');
    expect($user->sector_source)->toBe('google-business');
    expect($user->public_contact_number)->toBe('(03) 1111 1111');
});

// ── (b2) Sector precedence fix (2026-07-15): manual is permanent, for either
// account type; only a blank or previously google-sourced value may update ──

it('never overwrites a MANUALLY-set sector on a business google resync, even when the mapped category differs', function () {
    idsyncFakePlaces();
    $user = idsyncUser('bizmanualsector', 'business');
    $siteId = idsyncSite($user);
    // sector_source is deliberately not fillable (service-written) — forceFill
    // it directly, same as SectorController does on a manual pick.
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'manual'])->save();

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    // Google's data maps to 'barber' (idsyncPlaceDetailsResponse: "Barber shop")
    // — this is THE bug fix: business used to overwrite a manual sector on
    // every resync just like any other differing value.
    $user->refresh();
    expect($user->sector)->toBe('restaurant');
    expect($user->sector_source)->toBe('manual');
    // Confirms this connect really did run (workplace fields still overwrite).
    expect(Workplace::query()->where('site_id', $siteId)->value('name'))->toBe('Fade Lab');
});

it('never overwrites a MANUALLY-set sector on a partna google resync', function () {
    idsyncFakePlaces();
    $user = idsyncUser('partnamanualsector', 'partna');
    idsyncSite($user);
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'manual'])->save();

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $user->refresh();
    expect($user->sector)->toBe('restaurant');
    expect($user->sector_source)->toBe('manual');
});

it('still lets Google replace its OWN previously google-sourced sector on a business resync', function () {
    idsyncFakePlaces();
    $user = idsyncUser('bizgooglesector', 'business');
    idsyncSite($user);
    // A prior sync's value — not manual, so business precedence still applies.
    $user->forceFill(['sector' => 'cafe', 'sector_source' => 'google-business'])->save();

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $user->refresh();
    expect($user->sector)->toBe('barber'); // Google's new category wins over its own prior value
    expect($user->sector_source)->toBe('google-business');
});

// ── (c) Email is never written by Google ─────────────────────────────────────

it('never writes contact_email from a google connect, even for business', function () {
    idsyncFakePlaces();
    $user = idsyncUser('nomail', 'business');
    $siteId = idsyncSite($user);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();

    // Google returns no email → contact_email stays null and gets no stamp.
    expect($workplace->contact_email)->toBeNull();
    expect($workplace->field_sources)->not->toHaveKey('contact_email');
    // Other fields still synced, proving the connect ran.
    // Place Details' displayName ("Fade Lab Barbers", 16 chars) is over the
    // 15-char business-name cap — IdentitySync word-trims it to "Fade Lab".
    expect($workplace->name)->toBe('Fade Lab');
});

// ── (d) Manual upsert: manual provenance + mirrors + (business) display_name ─

it('manual upsert stamps manual source and mirrors public contact fields', function () {
    $user = idsyncUser('manualp', 'partna');
    idsyncSite($user);

    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Hand Typed Co',
        'phone' => '(03) 2222 2222',
        'contact_email' => 'hello@handtyped.example',
        'website' => 'https://handtyped.example',
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $user->site->id)->firstOrFail();
    expect($workplace->field_sources['name']['source'])->toBe('manual');
    expect($workplace->field_sources['phone']['source'])->toBe('manual');
    expect($workplace->field_sources['contact_email']['source'])->toBe('manual');
    expect($workplace->field_sources['website']['source'])->toBe('manual');

    // Mirrored onto the user's public contact columns.
    $user->refresh();
    expect($user->public_contact_number)->toBe('(03) 2222 2222');
    expect($user->public_contact_email)->toBe('hello@handtyped.example');
    // partna account → display_name NOT adopted from the workplace name.
    expect($user->display_name)->toBe('Manualp');
});

it('manual upsert on a business account mirrors the name to display_name', function () {
    $user = idsyncUser('manualbiz', 'business');
    idsyncSite($user);

    // Manual entry is capped at 15 chars by UpsertWorkplaceRequest (unlike
    // the auto-adopted Google path, which word-trims instead of rejecting).
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Trading Name',
        'phone' => '(03) 3333 3333',
    ])->assertOk();

    $user->refresh();
    // Business capability google_business_sets_display_name → name adopted.
    expect($user->display_name)->toBe('Trading Name');
    expect($user->public_contact_number)->toBe('(03) 3333 3333');
});

it('manual upsert preserves a google-business badge on a field the user did not send', function () {
    $user = idsyncUser('mixedsrc', 'partna');
    $siteId = idsyncSite($user);

    // A field previously synced from Google (website) with a google-business badge.
    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => 'Seed',
        'website' => 'https://from-google.example',
        'field_sources' => json_encode([
            'website' => ['source' => 'google-business', 'at' => '2026-01-01T00:00:00+00:00'],
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // User edits only name + phone (no website key in the request).
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Renamed',
        'phone' => '(03) 4444 4444',
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    expect($workplace->field_sources['name']['source'])->toBe('manual');
    expect($workplace->field_sources['phone']['source'])->toBe('manual');
    // The untouched website keeps its google-business provenance.
    expect($workplace->field_sources['website']['source'])->toBe('google-business');
});

// ── (e) Auto-adopted names are word-trimmed to the 15-char cap ───────────────

it('word-trims a Google-sourced name that exceeds the business-name cap', function () {
    config(['services.google_maps.server_api_key' => 'server-key', 'services.apify.token' => null]);
    Http::fake([
        'places.googleapis.com/v1/places/*/photos/*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg']),
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response([
            'id' => 'ChIJlongname',
            'displayName' => ['text' => 'Bayside Cafe And Bakery'],
            'location' => ['latitude' => -37.8, 'longitude' => 144.96],
        ]),
    ]);
    $user = idsyncUser('longname', 'business');
    $siteId = idsyncSite($user);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJlongname',
        'name' => 'Bayside Cafe',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    // "Bayside Cafe And Bakery" (24 chars) → whole words kept up to the cap.
    expect($workplace->name)->toBe('Bayside Cafe');
    expect(mb_strlen($workplace->name))->toBeLessThanOrEqual(15);
});

// ── Refresh path also folds identity (proves observer covers ->update) ───────

it('a scheduled refresh that changes the payload folds identity for a business account', function () {
    config(['services.google_maps.server_api_key' => 'server-key']);
    Http::fake([
        'places.googleapis.com/v1/places/*/photos/*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg']),
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response(['status' => 'ZERO_RESULTS']),
        'places.googleapis.com/*' => Http::response(idsyncPlaceDetailsResponse()),
    ]);
    $user = idsyncUser('refreshbiz', 'business');
    $siteId = idsyncSite($user);

    // A stale connection with a placeId — the cron re-pulls + persists via ->update.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => ['url' => 'https://old', 'placeId' => 'ChIJidsync', 'name' => 'Old Name', 'lat' => -37.0, 'lng' => 144.0],
        'last_refreshed_at' => now()->subWeek(),
    ]);

    $this->artisan('integrations:refresh')->assertSuccessful();

    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    // Place Details' displayName ("Fade Lab Barbers", 16 chars) is over the
    // 15-char business-name cap — IdentitySync word-trims it to "Fade Lab".
    expect($workplace->name)->toBe('Fade Lab');
    expect($workplace->opening_hours['mon'])->toBe([['open' => '0900', 'close' => '1730']]);
    $user->refresh();
    expect($user->sector)->toBe('barber');
});
