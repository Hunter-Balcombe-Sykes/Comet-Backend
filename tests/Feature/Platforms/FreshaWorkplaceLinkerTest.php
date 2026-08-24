<?php

// FreshaWorkplaceLinker (owner, 2026-08-19): a partna account's Fresha venue
// is looked up on Google and connected as Google Business when the name
// agrees AND a second detail (distance / postcode / phone) corroborates.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\FreshaWorkplaceLinker;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\Bus;
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

it('connects google business when the name agrees and the pin is close', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJstairs', 'Stairs Hair Salon', -37.83940, 144.99320),
            fwlPlace('ChIJother', 'Stairs Salon Northcote', -37.7700, 145.0000, '3070', '(03) 9000 0000'),
        ]]),
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJstairs', 'displayName' => ['text' => 'Stairs Hair Salon'],
            'formattedAddress' => '180 Toorak Rd, South Yarra VIC 3141',
            'location' => ['latitude' => -37.83940, 'longitude' => 144.99320],
        ]),
    ]);
    $user = fwlUser('linkme');

    $result = app(FreshaWorkplaceLinker::class)->attempt($user, fwlVenue());

    expect($result['outcome'])->toBe('connected');
    expect($result['placeId'])->toBe('ChIJstairs');
    $row = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', Platform::GoogleBusiness->value)->first();
    expect($row)->not->toBeNull();
    expect($row->place_id)->toBe('ChIJstairs');
    expect($row->payload['name'])->toBe('Stairs Hair Salon');
});

it('does not connect when the name agrees but nothing corroborates', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJfar', 'Stairs Hair Salon', -33.8688, 151.2093, '2000', '(02) 9000 0000'),
        ]]),
    ]);
    $user = fwlUser('farsalon');

    $result = app(FreshaWorkplaceLinker::class)->attempt($user, fwlVenue());

    expect($result['outcome'])->toBe('no_match');
    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('accepts a phone match as corroboration when the pin is missing', function () {
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

    expect(app(FreshaWorkplaceLinker::class)->attempt($user, $venue)['outcome'])->toBe('connected');
});

it('skips a business account and an account that already has google', function () {
    Http::fake();
    $business = fwlUser('bizsalon', 'business');
    expect(app(FreshaWorkplaceLinker::class)->attempt($business, fwlVenue())['reason'])->toBe('business_account');

    $user = fwlUser('hasgoogle');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => Platform::GoogleBusiness->value,
        'resource_id' => Platform::GoogleBusiness->value, 'payload' => ['name' => 'X', 'url' => 'https://maps.google.com'], 'is_active' => true,
    ]);
    expect(app(FreshaWorkplaceLinker::class)->attempt($user, fwlVenue())['reason'])->toBe('google_already_connected');
    Http::assertNothingSent();
});

it('treats two confident candidates as ambiguity', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response(['places' => [
            fwlPlace('ChIJa', 'Stairs Hair Salon', -37.83940, 144.99320),
            fwlPlace('ChIJb', 'Stairs Hair Salon', -37.83950, 144.99330),
        ]]),
    ]);
    $user = fwlUser('twins');
    expect(app(FreshaWorkplaceLinker::class)->attempt($user, fwlVenue())['outcome'])->toBe('no_match');
});
