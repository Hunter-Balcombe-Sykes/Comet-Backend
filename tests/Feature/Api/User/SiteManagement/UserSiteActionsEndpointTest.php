<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GET /api/site/actions — the dashboard picker data source for the design
// page's "Pages" / "Action buttons" controls: live pool + currently-served
// rankedActions + ordering settings.

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupContentPopularityScoresTable();
});

it('returns pool, rankedActions and ordering for the authed professional', function () {
    $pro = createTenant('actions-endpoint');
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $pro->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Instagram',
        'url' => 'https://instagram.com/x',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => 'instagram',
        'settings' => json_encode([]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $pro->site->id,
        'content_type' => 'action',
        'content_key' => 'button:instagram',
        'score' => 0.77,
        'rank' => 1,
        'computed_at' => now()->toISOString(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/site/actions')->assertOk();

    // success() responds with the body directly — no data envelope.
    $data = $response->json();
    $poolRefs = collect($data['pool'])->pluck('ref');

    expect($poolRefs)->toContain('instagram')
        ->and($poolRefs)->toContain('links')
        // Pool entries carry their stored score when ranked.
        ->and(collect($data['pool'])->firstWhere('ref', 'instagram')['score'])->toBe(0.77)
        ->and($data['rankedActions'][0]['ref'])->toBe('instagram')
        ->and($data['ordering'])->toMatchArray(['smartPageOrder' => true, 'smartActions' => true]);
});

it('requires authentication', function () {
    createTenant('actions-noauth');

    $this->getJson('/api/site/actions')->assertStatus(401);
});
