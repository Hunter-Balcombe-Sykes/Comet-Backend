<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\Content\LinkPoolWriter;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// `actions` on the public payload (spec §3): the unified top-N, in the owner's
// mode with locks applied, every ref resolving against the served pools.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupBlocksTable();
    setupServicesTable();
    setupSiteMediaTable();
    setupDesignKitsTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
    config(['partna.actions.slots' => 10]);
});

function actionsConnection(object $tenant, string $platform, array $payload = [], array $attrs = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => $id,
        'user_id' => $tenant->id,
        'surface_key' => $surface = (LegacyPlatformMap::surfaceFor($platform) ?? $platform),
        'routing_class' => LegacyPlatformMap::routingClassFor($surface),
        'resource_id' => 'r-'.Str::random(8),
        'resource_kind' => null,
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ], $attrs));

    return $id;
}

function actionsSettings(object $tenant, array $settings): void
{
    DB::connection('pgsql')->table('site.sites')->where('id', $tenant->site->id)->update(['settings' => json_encode($settings)]);
}

function actionsScore(string $siteId, string $actionId, float $score, int $rank): void
{
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'action',
        'content_key' => $actionId, 'score' => $score, 'rank' => $rank, 'computed_at' => now()->toISOString(),
    ]);
}

function actionsPayload(object $tenant): array
{
    $pro = $tenant->fresh(['site']);

    return app(IndividualProfilePayloadBuilder::class)->build($pro, $pro->site);
}

it('is always present — an empty site serves mode newest with no entries', function () {
    $tenant = createTenant('pa-empty');
    $payload = actionsPayload($tenant);

    expect($payload['actions'])->toBe(['mode' => 'smart', 'entries' => []]);
});

it('serves platforms and pool items in newest order, every ref resolving against the served pools', function () {
    $tenant = createTenant('pa-newest');
    actionsConnection($tenant, 'instagram', ['username' => 'maha'], ['created_at' => now()->subDays(30)->toISOString()]);
    $linkId = app(LinkPoolWriter::class)->add($tenant->refresh(), 'https://example.com/new-thing', 'New thing', enrich: false);

    $payload = actionsPayload($tenant);
    $entries = $payload['actions']['entries'];

    // smart + cold-start prior (2026-09-02): a platform outranks a single item.
    expect($payload['actions']['mode'])->toBe('smart')
        ->and(array_column($entries, 'id'))->toBe(['platform:instagram', 'item:'.$linkId])
        ->and($entries[1])->toMatchArray(['position' => 1, 'kind' => 'item', 'label' => 'New thing', 'url' => 'https://example.com/new-thing', 'locked' => false, 'ref' => ['pool' => 'custom_links', 'itemId' => $linkId]])
        ->and($entries[0])->toMatchArray(['position' => 0, 'kind' => 'platform', 'label' => 'Instagram', 'url' => 'https://www.instagram.com/maha', 'thumb' => null, 'ref' => null]);

    $pools = json_decode(json_encode($payload['profile']['pools']), true);
    $servedIds = array_column($pools['custom_links']['items'], 'id');
    foreach ($entries as $e) {
        if ($e['ref'] !== null) {
            expect($servedIds)->toContain($e['ref']['itemId']);
        }
    }
});

it('smart mode orders by stored action scores and honours a lock', function () {
    $tenant = createTenant('pa-smart');
    actionsConnection($tenant, 'instagram', ['username' => 'maha']);
    actionsConnection($tenant, 'tiktok', ['url' => 'https://tiktok.com/@maha']);
    actionsConnection($tenant, 'youtube', ['handle' => 'maha']);
    actionsScore($tenant->site->id, 'platform:tiktok', 0.9, 1);
    actionsScore($tenant->site->id, 'platform:instagram', 0.5, 2);
    actionsSettings($tenant, ['actions' => ['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'platform:youtube']]]]);

    $entries = actionsPayload($tenant)['actions']['entries'];

    expect(array_column($entries, 'id'))->toBe(['platform:youtube', 'platform:tiktok', 'platform:instagram'])
        ->and(array_column($entries, 'locked'))->toBe([true, false, false]);
});

it('manual mode serves exactly the slots; a slot whose candidate is gone is skipped', function () {
    $tenant = createTenant('pa-manual');
    actionsConnection($tenant, 'instagram', ['username' => 'maha']);
    actionsConnection($tenant, 'tiktok', ['url' => 'https://tiktok.com/@maha']);
    actionsSettings($tenant, ['actions' => ['mode' => 'manual', 'slots' => [
        ['position' => 0, 'id' => 'platform:tiktok'], ['position' => 1, 'id' => 'platform:gone'], ['position' => 2, 'id' => 'platform:instagram'],
    ]]]);

    $entries = actionsPayload($tenant)['actions']['entries'];

    expect(array_column($entries, 'id'))->toBe(['platform:tiktok', 'platform:instagram'])
        ->and(array_column($entries, 'position'))->toBe([0, 1])
        ->and(array_column($entries, 'locked'))->toBe([true, true]);
});

it('caps at the slot count and keeps positions contiguous', function () {
    $tenant = createTenant('pa-cap');
    config(['partna.actions.slots' => 2]);
    actionsConnection($tenant, 'instagram', ['username' => 'maha']);
    actionsConnection($tenant, 'tiktok', ['url' => 'https://tiktok.com/@maha']);
    actionsConnection($tenant, 'youtube', ['handle' => 'maha']);

    $entries = actionsPayload($tenant)['actions']['entries'];

    expect($entries)->toHaveCount(2)->and(array_column($entries, 'position'))->toBe([0, 1]);
});

it('a source connection whose page is present folds into the page action on the wire', function () {
    $tenant = createTenant('pa-fold');
    actionsConnection($tenant, 'fresha', ['url' => 'https://fresha.com/a/maha']);
    ownerServiceItem($tenant->id, ['title' => 'Haircut']);

    $ids = array_column(actionsPayload($tenant)['actions']['entries'], 'id');

    expect($ids)->toContain('page:services')->not->toContain('platform:fresha');
});
