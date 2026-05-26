<?php

use App\Http\Resources\UserPublicResource;
use App\Models\Core\User\User;
use Illuminate\Http\Request;

it('returns display_name and partna_url, no PII', function () {
    $pro = new User;
    $pro->setRawAttributes([
        'id' => 'pro-1',
        'handle' => 'evo',
        'handle_lc' => 'evo',
        'display_name' => 'Evo',
        'partna_url' => 'https://evo.partna.au',
        'bio' => 'Hair and beauty',
        'public_contact_number' => null,
        'public_contact_email' => 'shop@evo.example',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_country' => 'AU',
        'first_name' => 'SHOULD-NOT-LEAK',
        'last_name' => 'SHOULD-NOT-LEAK',
        'primary_email' => 'shouldnotleak@example.com',
    ]);

    $array = (new UserPublicResource($pro))->toArray(Request::create('/'));

    expect($array)
        ->toHaveKey('display_name', 'Evo')
        ->toHaveKey('partna_url', 'https://evo.partna.au')
        ->not->toHaveKey('handle')
        ->not->toHaveKey('first_name')
        ->not->toHaveKey('last_name')
        ->not->toHaveKey('primary_email');
});
