<?php

use App\Http\Resources\UserResource;
use App\Models\Core\User\User;
use Illuminate\Http\Request;

function buildPro(array $overrides = []): User
{
    $pro = new User;
    $pro->setRawAttributes(array_merge([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'partna_url' => 'https://evo.partna.au',
        'first_name' => null,
        'last_name' => null,
        'bio' => null,
        'about' => null,
        'phone' => null,
        'primary_email' => 'evo@example.com',
        'country_code' => 'AU',
        'timezone' => 'Australia/Sydney',
        'status' => 'active',
        'onboarding_step' => 0,
        'public_contact_number' => null,
        'public_contact_email' => null,
        'location_street_address' => null,
        'location_city' => null,
        'location_state' => null,
        'location_postcode' => null,
        'location_country' => null,
        'stripe_connect_status' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $pro;
}

it('returns display_name and partna_url', function () {
    $pro = buildPro(['display_name' => 'Push Pull', 'partna_url' => 'https://pushpull.partna.au']);
    $array = (new UserResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('display_name', 'Push Pull')
        ->toHaveKey('partna_url', 'https://pushpull.partna.au');
});

it('does not expose handle or internal fields', function () {
    $pro = buildPro();
    $array = (new UserResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->not->toHaveKey('handle')
        ->not->toHaveKey('handle_lc');
});
