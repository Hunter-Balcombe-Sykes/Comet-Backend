<?php

use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Public payload emission for the unified actions system (2026-07-23 rebuild):
// rankedActions (smart, cold-fallback, manual incl. customs), the ordering
// object, and the manual pageOrder path (drop unknown, append missing) —
// end-to-end through IndividualProfilePayloadBuilder::build().

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();     // sites + platform_connections
    setupContentSelectionTable(); // payload's designMedia resolves the selection
    setupBlocksTable();
    setupServicesTable();
    setupSiteMediaTable();
    setupDesignKitsTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
});

function payloadLinkBlock(object $tenant, array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert(array_merge([
        'id' => $id,
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'category' => 'social',
        'platform' => null,
        'settings' => json_encode([]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ], $attrs));

    return $id;
}

function insertActionScore(string $siteId, string $actionId, float $score, int $rank): void
{
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'action',
        'content_key' => $actionId,
        'score' => $score,
        'rank' => $rank,
        'computed_at' => now()->toISOString(),
    ]);
}

function setSiteSettings(object $tenant, array $settings): void
{
    DB::connection('pgsql')->table('site.sites')
        ->where('id', $tenant->site->id)
        ->update(['settings' => json_encode($settings)]);
}

function buildProfilePayload(object $tenant): array
{
    $pro = $tenant->fresh(['site']);

    return app(IndividualProfilePayloadBuilder::class)->build($pro, $pro->site);
}

it('emits rankedActions from stored action ranks, appending unscored pool entries by prior', function () {
    $tenant = createTenant('pay-smart');
    payloadLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);
    payloadLinkBlock($tenant, ['platform' => 'twitch', 'title' => 'Twitch', 'url' => 'https://twitch.tv/x', 'sort_order' => 1]);

    // Job has scored instagram; twitch connected "since" (unscored).
    insertActionScore($tenant->site->id, 'instagram', 0.81, 1);
    insertActionScore($tenant->site->id, 'discord', 0.99, 2); // left the pool → dropped

    $payload = buildProfilePayload($tenant);
    $actions = $payload['rankedActions'];

    expect($actions[0])->toMatchArray([
        'id' => 'instagram', 'kind' => 'external', 'label' => 'Instagram',
        'url' => 'https://instagram.com/x', 'platform' => 'instagram', 'score' => 0.81,
    ])
        // Unscored pool entry appended after the stored one, score null.
        ->and($actions[1]['id'])->toBe('twitch')
        ->and($actions[1]['score'])->toBeNull()
        // Stale stored key resolves to nothing — dropped entirely.
        ->and(collect($actions)->pluck('id'))->not->toContain('discord');
});

it('falls back to a prior-ordered pool when the job has not run yet', function () {
    $tenant = createTenant('pay-cold');
    payloadLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);

    $payload = buildProfilePayload($tenant);
    $ids = collect($payload['rankedActions'])->pluck('id');

    expect($ids)->toContain('instagram')
        ->and(collect($payload['rankedActions'])->pluck('score')->unique()->all())->toBe([null]);
});

it('serves the manual action list verbatim when smart_actions is off — customs kept, unknown refs dropped', function () {
    $tenant = createTenant('pay-manual');
    payloadLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);
    setSiteSettings($tenant, [
        'smart_actions' => false,
        'manual_actions' => [
            ['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example'],
            ['kind' => 'action', 'ref' => 'instagram'],
            ['kind' => 'action', 'ref' => 'tiktok'],   // not in pool → dropped
            ['kind' => 'action', 'ref' => 'menu'],     // menu page not present → dropped
        ],
    ]);

    $payload = buildProfilePayload($tenant);
    $actions = $payload['rankedActions'];

    expect(count($actions))->toBe(2)
        ->and($actions[0])->toMatchArray([
            'id' => null, 'kind' => 'custom', 'label' => 'Gift cards',
            'url' => 'https://gifts.example', 'pageId' => null, 'score' => null,
        ])
        ->and($actions[1])->toMatchArray(['id' => 'instagram', 'kind' => 'external', 'label' => 'Instagram']);

    // Ordering object carries the raw preferences for dashboard/preview surfaces.
    $ordering = (array) $payload['ordering'];
    expect($ordering['smartActions'])->toBeFalse()
        ->and($ordering['smartPageOrder'])->toBeTrue()
        ->and(count($ordering['manualActions']))->toBe(4);
});

it('drops custom actions with a non-http(s) url on the emit path (defense-in-depth vs unvalidated writers)', function () {
    $tenant = createTenant('pay-href');
    // Written DIRECTLY to settings, bypassing UpdateSiteRequest's url:http,https
    // rule — simulates StaffUpdateSiteRequest's generic settings passthrough (no
    // per-kind validation). The EMIT path must still refuse a script/data/relative
    // href so it can never reach the public payload as a button.
    setSiteSettings($tenant, [
        'smart_actions' => false,
        'manual_actions' => [
            ['kind' => 'custom', 'label' => 'XSS', 'url' => 'javascript:alert(1)'],
            ['kind' => 'custom', 'label' => 'Data', 'url' => 'data:text/html,<script>alert(1)</script>'],
            ['kind' => 'custom', 'label' => 'Scheme-less', 'url' => '//evil.example/x'],
            ['kind' => 'custom', 'label' => 'Relative', 'url' => '/not-absolute'],
            ['kind' => 'custom', 'label' => 'Safe', 'url' => 'https://safe.example/x'],
        ],
    ]);

    $actions = buildProfilePayload($tenant)['rankedActions'];

    expect(collect($actions)->pluck('url')->all())->toBe(['https://safe.example/x'])
        ->and($actions[0])->toMatchArray(['kind' => 'custom', 'label' => 'Safe', 'url' => 'https://safe.example/x']);
});

it('truncates an over-long custom label defensively at emit time', function () {
    $tenant = createTenant('pay-label');
    setSiteSettings($tenant, [
        'smart_actions' => false,
        'manual_actions' => [
            ['kind' => 'custom', 'label' => str_repeat('a', 200), 'url' => 'https://safe.example/x'],
        ],
    ]);

    $actions = buildProfilePayload($tenant)['rankedActions'];

    expect(mb_strlen($actions[0]['label']))->toBe(80);
});

it('applies manual page order when smart_page_order is off — unknown dropped, missing present pages appended', function () {
    $tenant = createTenant('pay-pageorder');
    payloadLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']); // links page
    DB::connection('pgsql')->table('site.platform_connections')->insert([                                              // listen page
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'spotify',
        'resource_id' => 'artist-1',
        'resource_kind' => 'profile',
        'payload' => json_encode([]),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
    setSiteSettings($tenant, [
        'smart_page_order' => false,
        // links hoisted first; 'shop' isn't present → dropped; home + listen
        // missing from the manual list → appended in canonical order.
        'manual_page_order' => ['links', 'shop'],
    ]);

    $payload = buildProfilePayload($tenant);

    expect($payload['pageOrder'])->toBe(['links', 'home', 'listen']);
});

it('emits ordering defaults (smart everywhere) and keeps the popularity map action-free', function () {
    $tenant = createTenant('pay-defaults');
    payloadLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);
    insertActionScore($tenant->site->id, 'instagram', 0.7, 1);

    $payload = buildProfilePayload($tenant);

    $ordering = (array) $payload['ordering'];
    expect($ordering)->toBe([
        'smartPageOrder' => true,
        'manualPageOrder' => [],
        'smartActions' => true,
        'manualActions' => [],
    ]);

    // The derived 'action' rows never leak into the content popularity map —
    // they have their own wire key (rankedActions).
    expect((array) $payload['popularity'])->not->toHaveKey('action');
});
