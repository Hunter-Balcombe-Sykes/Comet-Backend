<?php

// FreshaWorkplaceLinker (owner, 2026-08-19): a partna account's Fresha venue
// is looked up on Google, and every name-agreeing listing is PROPOSED as a
// workplace candidate — distance / postcode / phone corroborate how
// confident a given candidate is, but corroboration no longer decides
// whether to connect: nothing here connects anything without an accept
// step (proposeCandidates() writes candidates; connect() is the accept).
// The old single-confident-match attempt() that auto-connected for a
// claimed owner was retired 2026-09-06 (same bug class as the
// GlossGenius/Fresha booking auto-connect closed the same day).

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaWorkplaceLinker;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupSectionsTables();
    AccountCapabilities::flushCache();
    config(['services.google_maps.server_api_key' => 'server-key']);
    config(['services.apify.token' => null]);
    Bus::fake();
});

function fwlUser(string $handle, string $type = 'partna'): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle), 'account_type' => $type, 'status' => 'active',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$handle}@example.com",
    ]);
}

function fwlVenue(): array
{
    return [
        'name' => 'Stairs Hair Salon', 'street' => '180 Toorak Road', 'city' => 'Melbourne',
        'postcode' => '3141', 'region' => 'Victoria', 'country' => 'AU',
        'lat' => -37.83932, 'lng' => 144.99314, 'phone' => '+61 3 9827 8033',
    ];
}

function fwlPlace(string $id, string $name, float $lat, float $lng, string $postcode = '3141', string $phone = '(03) 9827 8033'): array
{
    return [
        'id' => $id,
        'displayName' => ['text' => $name],
        'formattedAddress' => '180 Toorak Rd, South Yarra VIC 3141',
        'location' => ['latitude' => $lat, 'longitude' => $lng],
        'postalAddress' => ['postalCode' => $postcode],
        'nationalPhoneNumber' => $phone,
        'businessStatus' => 'OPERATIONAL',
    ];
}

it('proposes a candidate when the name agrees and the pin is close, corroborated by distance', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJstairs', 'Stairs Hair Salon', -37.83940, 144.99320),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJstairs', 'displayName' => ['text' => 'Stairs Hair Salon'],
            'formattedAddress' => '180 Toorak Rd, South Yarra VIC 3141',
            'location' => ['latitude' => -37.83940, 'longitude' => 144.99320],
        ]),
    ]);
    $user = fwlUser('linkme');

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha');

    expect($written)->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $row = DB::table('site.workplace_candidates')->where('user_id', $user->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->place_id)->toBe('ChIJstairs')
        ->and($row->state)->toBe('proposed')
        ->and(json_decode($row->corroboration, true))->toContain('distance');
});

it('still proposes the single candidate when the name agrees but nothing else corroborates', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJfar', 'Stairs Hair Salon', -33.8688, 151.2093, '2000', '(02) 9000 0000'),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJfar']),
    ]);
    $user = fwlUser('farsalon');

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha');

    expect($written)->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $row = DB::table('site.workplace_candidates')->where('user_id', $user->id)->first();
    expect(json_decode($row->corroboration, true))->toBe(['name']);
});

it('still records a phone match as a corroborator when the pin is missing', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJphone', 'Stairs Hair', -37.0, 144.0, '9999', '03 9827 8033'),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJphone', 'displayName' => ['text' => 'Stairs Hair']]),
    ]);
    $user = fwlUser('phonesalon');
    $venue = fwlVenue();
    $venue['lat'] = null;
    $venue['lng'] = null;

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, $venue, 'fresha');

    expect($written)->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $row = DB::table('site.workplace_candidates')->where('user_id', $user->id)->first();
    expect(json_decode($row->corroboration, true))->toContain('phone');
});

it('proposeCandidates refuses an account that already has google', function () {
    Http::fake();
    $user = fwlUser('hasgoogle');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => Platform::GoogleBusiness->value,
        'resource_id' => Platform::GoogleBusiness->value, 'payload' => ['name' => 'X', 'url' => 'https://maps.google.com'], 'is_active' => true,
    ]);

    expect(app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha'))->toBe(0);
    Http::assertNothingSent();
});

it('proposes both candidates when two are equally confident (no auto-connect needed to disambiguate)', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJa', 'Stairs Hair Salon', -37.83940, 144.99320),
            fwlPlace('ChIJb', 'Stairs Hair Salon', -37.83950, 144.99330),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response([]),
    ]);
    $user = fwlUser('twins');

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha');

    expect($written)->toBe(2)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $rows = DB::table('site.workplace_candidates')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect(json_decode($row->corroboration, true))->toContain('distance');
    }
});

it('still proposes a locality-corroborated single hit when the venue offers no other corroborator (owner, 2026-08-27)', function () {
    // The bio-mention shape: the venue's own IG bio carries only opening
    // hours — no address, postcode, phone or pin. The name token "darwin"
    // appearing in the ONE name-agreeing candidate's own address is the
    // accepted agreement.
    $user = fwlUser('fwl-locality');
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [[
            'id' => 'place-star',
            'displayName' => ['text' => 'Star Barber Darwin'],
            'formattedAddress' => 'Shop 6/32 Smith St Mall, Darwin City NT 0800, Australia',
            'location' => ['latitude' => -12.46, 'longitude' => 130.84],
            'businessStatus' => 'OPERATIONAL',
        ]]]),
        'places.googleapis.com/v1/places/*' => Http::response([]),
    ]);

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, [
        'name' => 'Star Barber Darwin',
        'street' => null, 'city' => null, 'postcode' => null,
        'region' => null, 'country' => 'AU', 'lat' => null, 'lng' => null, 'phone' => null,
    ], 'bio_mention');

    expect($written)->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $row = DB::table('site.workplace_candidates')->where('user_id', $user->id)->first();
    expect(json_decode($row->corroboration, true))->toContain('name-locality');
});

it('still proposes a name-only candidate when the name carries no locality token in the candidate address', function () {
    $user = fwlUser('fwl-noloc');
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [[
            'id' => 'place-x',
            'displayName' => ['text' => 'Star Barber'],
            'formattedAddress' => '1 Collins St, Melbourne VIC 3000, Australia',
            'location' => ['latitude' => -37.8, 'longitude' => 144.9],
            'businessStatus' => 'OPERATIONAL',
        ]]]),
        'places.googleapis.com/v1/places/*' => Http::response([]),
    ]);

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, [
        'name' => 'Star Barber',
        'street' => null, 'city' => null, 'postcode' => null,
        'region' => null, 'country' => 'AU', 'lat' => null, 'lng' => null, 'phone' => null,
    ], 'bio_mention');

    expect($written)->toBe(1)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $row = DB::table('site.workplace_candidates')->where('user_id', $user->id)->first();
    expect(json_decode($row->corroboration, true))->toBe(['name']);
});

// ── A.5: candidates persisted for the setup dialog's listing pass ───────────

it('proposeCandidates writes every name-agreeing venue and connects nothing', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJconfident', 'Stairs Hair Salon', -37.83940, 144.99320),
            fwlPlace('ChIJnamesake', 'Stairs Salon Northcote', -37.7700, 145.0000, '3070', '(03) 9000 0000'),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJconfident', 'displayName' => ['text' => 'Stairs Hair Salon'],
            'rating' => 4.9, 'userRatingCount' => 120,
        ]),
    ]);
    $user = fwlUser('candidates-two');

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha');

    expect($written)->toBe(2)
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);

    $rows = DB::table('site.workplace_candidates')
        ->where('user_id', $user->id)->orderBy('place_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->state)->toBe('proposed')
        ->and(count(json_decode($rows[0]->corroboration, true)))->toBeGreaterThanOrEqual(2)
        ->and(json_decode($rows[1]->corroboration, true))->toBe(['name']);
});

it('proposeCandidates never reopens a row the person already answered', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJanswered', 'Stairs Hair Salon', -37.83940, 144.99320),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJanswered']),
    ]);
    $user = fwlUser('candidates-answered');
    DB::table('site.workplace_candidates')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'place_id' => 'ChIJanswered',
        'name' => 'Stairs Hair Salon', 'source' => 'fresha', 'corroboration' => '["name"]',
        'state' => 'dismissed', 'created_at' => now(),
    ]);

    $written = app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'fresha');

    expect($written)->toBe(0)
        ->and(DB::table('site.workplace_candidates')->where('user_id', $user->id)->value('state'))->toBe('dismissed');
});

it('proposeCandidates refuses a business account', function () {
    Http::fake();
    $user = fwlUser('candidates-biz', 'business');

    expect(app(FreshaWorkplaceLinker::class)->proposeCandidates($user, fwlVenue(), 'bio_mention'))->toBe(0);
    Http::assertNothingSent();
});
