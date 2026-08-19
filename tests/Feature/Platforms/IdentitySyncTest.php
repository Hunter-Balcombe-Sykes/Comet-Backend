<?php

// Central-identity precedence engine (IdentitySync) + the manual workplace
// upsert's provenance/mirror behaviour. Exercises the account-type split
// through AccountCapabilities::google_business_full_sync — business overwrites,
// partna fills gaps — plus the "email never from Google" and phone-mirror rules.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\IdentitySync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also sets up site.workplaces + site.platform_connections
    // The manual upsert re-evaluates the 'workplace' section's visibility, which
    // queries site.blocks — needed even though no block row is seeded.
    setupBlocksTable();
    // FoodContentProbe queries site.pages, which lives under site.sections' setup.
    setupSectionsTables();
    AccountCapabilities::flushCache();
});

function idsyncUser(string $handle, string $accountType): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
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
        'postalAddress' => [
            'addressLines' => ['12 Example St'],
            'locality' => 'Melbourne',
            'administrativeArea' => 'VIC',
            'postalCode' => '3000',
            'regionCode' => 'AU',
        ],
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
    expect($workplace->address_line1)->toBe('12 Example St');
    expect($workplace->city)->toBe('Melbourne');
    expect($workplace->state)->toBe('VIC');
    expect($workplace->postcode)->toBe('3000');
    expect($workplace->country)->toBe('AU');
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
    expect($workplace->address_line1)->toBe('12 Example St');
    expect($workplace->city)->toBe('Melbourne');
    expect($workplace->field_sources['website']['source'])->toBe('google-business');
    expect($workplace->opening_hours['mon'])->toBe([['open' => '0900', 'close' => '1730']]);

    // 2026-08-19 identity plan: NOTHING mirrors onto a partna's user row from
    // a workplace sync. Sector stays blank (Instagram is their one automated
    // source now) and the public contact number is untouched — the workplace
    // is where they WORK, not who they are.
    $user->refresh();
    expect($user->sector)->toBeNull();
    expect($user->sector_source)->toBeNull();
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

it('lets Google FILL a sector that was manually CLEARED — (null, manual) rows never block the fill', function () {
    idsyncFakePlaces();
    $user = idsyncUser('bizclearedsector', 'business');
    idsyncSite($user);
    // The pre-fix stuck state: SectorController used to stamp 'manual' even on
    // a clear. Manual permanence must apply to a manual VALUE, not a null.
    $user->forceFill(['sector' => null, 'sector_source' => 'manual'])->save();

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    $user->refresh();
    expect($user->sector)->toBe('barber');
    expect($user->sector_source)->toBe('google-business');
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

it('overwrites an instagram-sourced sector on a business google resync', function () {
    // Was the opposite until 2026-08-12. Commit 30e3d3abb widened a guard meant
    // to protect a MANUAL pick so it protected every non-Google source, which
    // let a scraper's guess outrank Google permanently.
    // Site MUST exist before the forceFill+save below: UserObserver::updated's
    // catch-all cache-bust (UserCacheService::invalidateUser) touches
    // $user->site synchronously (no wrapping transaction in tests), which
    // would cache the relation as null forever if the site row didn't exist
    // yet — silently turning applyFromGooglePayload into a no-op. Every other
    // sector test in this file creates the site first for the same reason.
    $user = idsyncUser('bizgooglesector2', 'business');
    idsyncSite($user);
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'instagram'])->save();

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('barber')
        ->and($user->sector_source)->toBe('google-business');
});

it('leaves a partna sector alone entirely — Google no longer writes it (2026-08-19 identity plan)', function () {
    // Reverses the pre-plan rule this test used to pin: a partna's industry
    // must not be set by where they WORK. Instagram (their own account) is
    // the sole automated source now; the Google fold is business-only.
    $user = idsyncUser('partnagooglesector', 'partna');
    idsyncSite($user);
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'instagram'])->save();

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('artist')
        ->and($user->sector_source)->toBe('instagram');
});

it('never overwrites a manual sector pick, on either account type', function (string $accountType) {
    // Site before forceFill+save — see the comment on the first "overwrites" test above.
    $user = idsyncUser("manualsector{$accountType}", $accountType);
    idsyncSite($user);
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'manual'])->save();

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('artist')
        ->and($user->sector_source)->toBe('manual');
})->with(['business', 'partna']);

it('refuses to demote a business out of food while food content is live', function () {
    // Site before forceFill+save — see the comment on the first "overwrites" test above.
    $user = idsyncUser('foodlock', 'business');
    $siteId = idsyncSite($user);
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'google-business'])->save();
    DB::connection('pgsql')->table('site.pages')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId,
        'key' => 'menu', 'label' => 'Menu', 'sort_order' => 1, 'capability' => 'menu',
        // site.pages.created_at/updated_at are NOT NULL with no default (test
        // schema mirrors the real migration) — the brief's insert omitted them.
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Event venue']);

    $user->refresh();
    expect($user->sector)->toBe('restaurant');
});

it('allows the demotion when no food content exists', function () {
    // Site before forceFill+save — see the comment on the first "overwrites" test above.
    $user = idsyncUser('foodfree', 'business');
    idsyncSite($user);
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'google-business'])->save();

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Event venue']);

    $user->refresh();
    expect($user->sector)->toBe('event-venue');
});

it('touches the site when the sector changes so the edge cache purges', function () {
    $user = idsyncUser('sectortouch', 'partna');
    $siteId = idsyncSite($user);
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => '2020-01-01 00:00:00']);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $touched = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    expect($touched)->not->toBe('2020-01-01 00:00:00');
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

it('manual upsert stamps manual source and mirrors NOTHING onto a partna user row', function () {
    // 2026-08-19 identity plan: the workplace's contact pair no longer
    // mirrors for partna — workplace fields and user fields are independent.
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

    // The user's own public pair stays untouched — TWO of each thing.
    $user->refresh();
    expect($user->public_contact_number)->toBeNull();
    expect($user->public_contact_email)->toBeNull();
    // partna account → display_name NOT adopted from the workplace name.
    expect($user->display_name)->toBe('Manualp');
});

it('manual upsert on a business account mirrors identity fields but NOT display_name', function () {
    // Decision 8 (2026-08-19): display_name is user-owned after Google's
    // initial seed — the manual workplace-name mirror is gone. The identity
    // mirror (contact, description, address) still runs for business.
    $user = idsyncUser('manualbiz', 'business');
    idsyncSite($user);

    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Trading Name',
        'phone' => '(03) 3333 3333',
        'contact_email' => 'shop@trading.example',
        'description' => 'A very fine shop.',
        'address_line1' => '1 Mirror Lane',
        'city' => 'Melbourne',
    ])->assertOk();

    $user->refresh();
    expect($user->display_name)->toBe('Manualbiz');
    expect($user->public_contact_number)->toBe('(03) 3333 3333');
    expect($user->public_contact_email)->toBe('shop@trading.example');
    expect($user->bio)->toBe('A very fine shop.');
    expect($user->location_street_address)->toBe('1 Mirror Lane');
    expect($user->location_city)->toBe('Melbourne');
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

// ── Resync (owner, 2026-08-19): an overridden partna field goes back under Google ──

it('partna resync puts a hand-edited field back under google and reports google_fields', function () {
    idsyncFakePlaces();
    $user = idsyncUser('resyncme', 'partna');
    $siteId = idsyncSite($user);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    // The user overrides the phone by hand — provenance flips to manual.
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Fade Lab',
        'phone' => '+61 400 000 000',
    ])->assertOk();
    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    expect($workplace->phone)->toBe('+61 400 000 000');
    expect(($workplace->field_sources ?? [])['phone']['source'] ?? null)->toBe('manual');

    // show advertises what Google can supply.
    $show = actingAsUser($user)->getJson('/api/site/workplace')->assertOk()->json();
    expect($show['google_fields'])->toContain('phone')->toContain('name')->toContain('address_line1');

    // Resync the phone: Google's value returns, stamped google-business; the
    // hand-typed name is untouched.
    $response = actingAsUser($user)->postJson('/api/site/workplace/resync', ['fields' => ['phone']])->assertOk()->json();
    expect($response['resynced'])->toBe(['phone']);
    $workplace->refresh();
    expect($workplace->phone)->toBe('(03) 9123 4567');
    expect(($workplace->field_sources ?? [])['phone']['source'] ?? null)->toBe('google-business');
    expect($workplace->name)->toBe('Fade Lab');
});

it('resync of any address column moves the whole address unit', function () {
    idsyncFakePlaces();
    $user = idsyncUser('resyncaddr', 'partna');
    $siteId = idsyncSite($user);
    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync', 'name' => 'Fade Lab', 'lat' => -37.0, 'lng' => 144.0,
    ])->assertOk();
    actingAsUser($user)->putJson('/api/site/workplace', [
        'name' => 'Fade Lab', 'address_line1' => '1 Nowhere Rd', 'city' => 'Elsewhere', 'postcode' => '9999',
    ])->assertOk();

    $response = actingAsUser($user)->postJson('/api/site/workplace/resync', ['fields' => ['address_line1']])->assertOk()->json();
    expect($response['resynced'])->toContain('address_line1')->toContain('city')->toContain('postcode');
    $workplace = Workplace::query()->where('site_id', $siteId)->firstOrFail();
    expect($workplace->address_line1)->toBe('12 Example St');
    expect($workplace->city)->toBe('Melbourne');
    expect($workplace->postcode)->toBe('3000');
});

it('resync 422s when google has nothing for the field', function () {
    $user = idsyncUser('resyncnone', 'partna');
    idsyncSite($user);
    actingAsUser($user)->postJson('/api/site/workplace/resync', ['fields' => ['phone']])->assertStatus(422);
});

// ── (f) The partna fill-only contract reaches core.users, not just workplaces ──
//
// IdentitySync's docblock promises: "partna ($overwrite = false) → Google fills
// gaps only; never clobbers a value the user set by hand." That guard is applied
// per-field on site.workplaces (IdentitySync::applyWorkplaceFields), then the row
// is saved — which fires WorkplaceObserver::mirrorContactFields BEFORE
// applyUserIdentityFields is ever reached. The observer carries no $overwrite
// notion, so it re-published Google's number over the hand-typed one and
// IdentitySync's own guard then no-opped, having nothing left to protect.
//
// The distinction matters because core.users.public_contact_number is the column
// the PUBLIC PAGE renders (profile.publicContact) — the workplace card being
// correct is no consolation if the number visitors call is Google's.

it('a partna google connect does not clobber a hand-typed public contact number', function () {
    idsyncFakePlaces();
    $user = idsyncUser('partnakeepsphone', 'partna');
    $siteId = idsyncSite($user);

    // Hand-typed, exactly as PATCH /api/me would leave it. No workplace row yet,
    // so workplaces.phone is BLANK — Google filling it is legitimate fill-if-empty
    // and the workplace write under test is NOT itself a violation.
    $user->update(['public_contact_number' => '+61400111222']);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    // The workplace card legitimately takes Google's number (it was empty)...
    expect(Workplace::query()->where('site_id', $siteId)->value('phone'))->toBe('(03) 9123 4567');

    // ...but the user's own public number is theirs and must survive.
    $user->refresh();
    expect($user->public_contact_number)->toBe('+61400111222');
});

it('a business google connect still replaces the public contact number', function () {
    idsyncFakePlaces();
    $user = idsyncUser('bizkeepsgoogle', 'business');
    idsyncSite($user);
    $user->update(['public_contact_number' => '+61400111222']);

    actingAsUser($user)->postJson('/api/platforms/google-business/connect', [
        'placeId' => 'ChIJidsync',
        'name' => 'Fade Lab',
        'lat' => -37.0,
        'lng' => 144.0,
    ])->assertOk();

    // Business grants Google authority (google_business_full_sync) — the fix must
    // not turn the partna guard into a blanket one.
    $user->refresh();
    expect($user->public_contact_number)->toBe('(03) 9123 4567');
});
