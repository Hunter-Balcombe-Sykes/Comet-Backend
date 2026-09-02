<?php

use App\Services\Shop\DiscountCodeAdopter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function adopterStorefront(string $userId, string $url, string $code = ''): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('content.storefronts')->insert([
        'collection_id' => $id, 'user_id' => $userId, 'provider' => 'woocommerce',
        'external_ref' => parse_url($url, PHP_URL_HOST), 'url' => $url, 'source_url' => $url.'/', 'discount_code' => $code,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('fills an empty discount code by host and never overwrites an owner-set one', function () {
    $user = createTenant('adopt-'.Str::lower(Str::random(5)));
    $empty = adopterStorefront($user->id, 'https://gammaplus.com.au');
    $owned = adopterStorefront($user->id, 'https://jukesgrooming.com', 'OWNER5');

    $adopter = app(DiscountCodeAdopter::class);
    expect($adopter->adopt($user, 'https://www.gammaplus.com.au/collections/all', 'TEEGAN10'))->toBe(1)
        ->and($adopter->adopt($user, 'https://jukesgrooming.com', 'NOPE'))->toBe(0)
        ->and($adopter->adopt($user, 'https://unknown-store.example', 'X'))->toBe(0);
    expect(DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $empty)->value('discount_code'))->toBe('TEEGAN10')
        ->and(DB::connection('pgsql')->table('content.storefronts')->where('collection_id', $owned)->value('discount_code'))->toBe('OWNER5');
});
