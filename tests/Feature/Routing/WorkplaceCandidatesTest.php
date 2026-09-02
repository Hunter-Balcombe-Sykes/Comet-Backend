<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// A.5 wire: workplace candidates render as candidate:<id> suggestion rows
// with a corroboration-derived band; accept connects and supersedes the
// siblings; dismiss records the refusal durably.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    config(['services.google_maps.server_api_key' => 'server-key']);
    config(['services.apify.token' => null]);
});

function seedCandidate(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('site.workplace_candidates')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'place_id' => 'ChIJ'.substr($id, 0, 8),
        'name' => 'Akro Studio',
        'address' => '5 Somewhere St, Melbourne VIC',
        'photo_url' => 'https://example.com/p.jpg',
        'rating' => 4.8,
        'review_count' => 55,
        'source' => 'bio_mention',
        'corroboration' => '["name","distance"]',
        'state' => 'proposed',
        'created_at' => now(),
    ], $overrides));

    return $id;
}

it('renders candidate rows with band and preselected', function () {
    $pro = createTenant('wc-render');
    seedCandidate($pro->id);
    seedCandidate($pro->id, ['name' => 'Akro Records', 'corroboration' => '["name"]']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    $rows = collect($response->json('suggestions'))->filter(fn ($s) => str_starts_with($s['id'], 'candidate:'))->values();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('displayName', 'Akro Studio')['band'])->toBe('auto')
        ->and($rows->firstWhere('displayName', 'Akro Studio')['preselected'])->toBeTrue()
        ->and($rows->firstWhere('displayName', 'Akro Records')['band'])->toBe('suggest')
        ->and($rows->firstWhere('displayName', 'Akro Studio')['surfaceKey'])->toBe('google_business.listing');
});

it('accept connects the candidate, adopts it and supersedes the siblings', function () {
    Http::fake([
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJx', 'displayName' => ['text' => 'Akro Studio']]),
    ]);
    $pro = createTenant('wc-accept');
    $chosen = seedCandidate($pro->id);
    $other = seedCandidate($pro->id, ['name' => 'Akro Records', 'corroboration' => '["name"]']);

    $response = actingAsUser($pro)->postJson("/api/routing/suggestions/candidate:{$chosen}/accept");

    $response->assertOk();
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'google-business')->exists())->toBeTrue()
        ->and(DB::table('site.workplace_candidates')->where('id', $chosen)->value('state'))->toBe('adopted')
        ->and(DB::table('site.workplace_candidates')->where('id', $other)->value('state'))->toBe('superseded');
});

it('dismiss settles the row and it never re-renders', function () {
    $pro = createTenant('wc-dismiss');
    $id = seedCandidate($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/candidate:{$id}/dismiss")->assertOk();

    expect(DB::table('site.workplace_candidates')->where('id', $id)->value('state'))->toBe('dismissed');
    $rows = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))
        ->filter(fn ($s) => str_starts_with($s['id'], 'candidate:'));
    expect($rows)->toHaveCount(0);
});

it('suppresses candidate rows once a Google Business connection exists', function () {
    $pro = createTenant('wc-suppressed');
    seedCandidate($pro->id);
    IntegrationConnection::create([
        'user_id' => $pro->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['name' => 'Existing'], 'is_active' => true,
    ]);

    $rows = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))
        ->filter(fn ($s) => str_starts_with($s['id'], 'candidate:'));
    expect($rows)->toHaveCount(0);
});

it('never lets one user settle another user\'s candidate', function () {
    $a = createTenant('wc-owner-a');
    $b = createTenant('wc-owner-b');
    $id = seedCandidate($a->id);

    actingAsUser($b)->postJson("/api/routing/suggestions/candidate:{$id}/dismiss")->assertStatus(404);
    actingAsUser($b)->postJson("/api/routing/suggestions/candidate:{$id}/accept")->assertStatus(404);
});
