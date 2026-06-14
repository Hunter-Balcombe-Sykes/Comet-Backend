<?php

use App\Http\Resources\UserDashboardResource;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    // UserDashboardResource reads the `site` relationship (custom_domain fields),
    // so the table must exist even though these users have no site row.
    setupSitesTable();
});

function makeAboutUser(array $attrs = []): User
{
    $id = (string) Str::uuid();
    $handle = 'about-'.substr($id, 0, 8);

    return User::create(array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'About Pro',
        'first_name' => 'About',
        'phone' => '+61400000000',
        'primary_email' => $handle.'@example.com',
        'status' => 'active',
    ], $attrs));
}

it('persists a full about payload and reads it back as an array', function () {
    $pro = makeAboutUser();

    $pro->about = [
        'credentials' => [
            ['title' => 'Advanced Colourist', 'issuer' => 'Toni & Guy', 'year' => 2019],
        ],
        'experience' => [
            ['role' => 'Senior Stylist', 'place' => 'Rokstar', 'start' => '2021-03', 'end' => null, 'description' => 'Led colour team.'],
        ],
    ];
    $pro->save();

    $fresh = User::query()->where('id', $pro->id)->first();

    expect($fresh->about)->toBeArray();
    expect($fresh->about['credentials'][0]['title'])->toBe('Advanced Colourist');
    expect($fresh->about['credentials'][0]['year'])->toBe(2019);
    expect($fresh->about['experience'][0]['start'])->toBe('2021-03');
    expect($fresh->about['experience'][0]['end'])->toBeNull();
});

it('exposes about through UserDashboardResource', function () {
    $pro = makeAboutUser([
        'about' => [
            'credentials' => [['title' => 'Cert', 'issuer' => 'Academy', 'year' => 2020]],
            'experience' => [],
        ],
    ]);

    $array = (new UserDashboardResource($pro->fresh()))->toArray(request());

    expect($array)->toHaveKey('about');
    expect($array['about']->credentials[0]['title'])->toBe('Cert');
    expect($array['about']->experience)->toBe([]);
});

it('returns an object that JSON-encodes as {} when about has never been set', function () {
    $pro = makeAboutUser();

    $array = (new UserDashboardResource($pro->fresh()))->toArray(request());

    // The resource casts $this->about to (object), so json_encode renders
    // an empty about as '{}' (not '[]').
    expect(json_encode($array['about']))->toBe('{}');
});

it('fill() accepts about from validated Request payload', function () {
    // Simulates what the controller does: $professional->fill($request->validated())
    $pro = makeAboutUser();

    $pro->fill([
        'display_name' => 'Renamed',
        'about' => [
            'credentials' => [['title' => 'New Cert']],
        ],
    ])->save();

    $fresh = $pro->fresh();
    expect($fresh->display_name)->toBe('Renamed');
    expect($fresh->about['credentials'][0]['title'])->toBe('New Cert');
});
