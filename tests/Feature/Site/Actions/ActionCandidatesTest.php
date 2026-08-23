<?php

use App\Catalog\LegacyPlatformMap;
use App\Site\Actions\ActionCandidates;
use App\Site\Actions\ActionId;
use App\Support\UrlSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ActionCandidates::forSite — the fold rule (spec §2, D2): a source
// connection that powers a present page is represented ONLY by that page's
// action; destination platforms get their own; a source whose page is
// absent falls back to its own URL.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupSectionsTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    setupServicesTable();
});

function candidateConnection(object $tenant, string $platform, array $payload = [], array $attrs = []): string
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

function candidatesFor(object $tenant): array
{
    $pro = $tenant->fresh(['site']);

    return app(ActionCandidates::class)->forSite($pro, $pro->site);
}

it('a bare site has no candidates', function () {
    $tenant = createTenant('ac-bare');
    expect(candidatesFor($tenant))->toBe([]);
});

it('a destination platform yields platform:<key> with its profile url and label', function () {
    $tenant = createTenant('ac-insta');
    candidateConnection($tenant, 'instagram', ['username' => 'maha']);
    candidateConnection($tenant, 'spotify', ['url' => 'https://open.spotify.com/artist/maha']);

    $out = collect(candidatesFor($tenant))->keyBy('id');

    expect($out->keys()->all())->toEqualCanonicalizing(['platform:instagram', 'platform:spotify'])
        ->and($out['platform:instagram'])->toMatchArray(['kind' => 'platform', 'label' => 'Instagram', 'url' => 'https://www.instagram.com/maha', 'ref' => null])
        ->and($out['platform:instagram']['connectedAt'])->not->toBeNull()
        ->and($out['platform:spotify']['url'])->toBe('https://open.spotify.com/artist/maha');
});

it('fold: a booking source with the services page present yields page:services and NO platform entry', function () {
    $tenant = createTenant('ac-fold');
    candidateConnection($tenant, 'fresha', ['url' => 'https://fresha.com/a/maha']);
    ownerServiceItem($tenant->id, ['title' => 'Haircut']);

    $out = collect(candidatesFor($tenant))->keyBy('id');

    expect($out->has('page:services'))->toBeTrue()
        ->and($out->has('platform:fresha'))->toBeFalse()
        ->and($out['page:services'])->toMatchArray(['kind' => 'page', 'label' => 'Book', 'url' => '/services', 'ref' => null])
        ->and($out['page:services']['meta'])->toBe(['pageId' => 'services']);
});

it('source fallback: a booking source whose services page is absent yields platform:fresha with the booking url', function () {
    $tenant = createTenant('ac-fallback');
    candidateConnection($tenant, 'fresha', ['url' => 'https://fresha.com/a/maha']);

    $out = collect(candidatesFor($tenant))->keyBy('id');

    expect($out->has('page:services'))->toBeFalse()
        ->and($out['platform:fresha'])->toMatchArray(['kind' => 'platform', 'label' => 'Fresha', 'url' => 'https://fresha.com/a/maha'])
        ->and($out['platform:fresha']['meta']['fallback'])->toBeTrue();
});

it('a platform that is both source and destination (youtube) keeps its platform action', function () {
    $tenant = createTenant('ac-yt');
    candidateConnection($tenant, 'youtube', ['handle' => 'maha']);

    $out = collect(candidatesFor($tenant))->keyBy('id');

    expect($out['platform:youtube']['url'])->toBe('https://www.youtube.com/@maha');
});

it('one platform action per platform key — the earliest connection wins', function () {
    $tenant = createTenant('ac-dupe');
    candidateConnection($tenant, 'tiktok', ['url' => 'https://tiktok.com/@first'], ['created_at' => now()->subDay()->toISOString()]);
    candidateConnection($tenant, 'tiktok', ['url' => 'https://tiktok.com/@second']);

    $out = collect(candidatesFor($tenant))->keyBy('id');

    expect($out->count())->toBe(1)->and($out['platform:tiktok']['url'])->toBe('https://tiktok.com/@first');
});

it('every candidate id passes the grammar and every url passes UrlSafety', function () {
    $tenant = createTenant('ac-safe');
    candidateConnection($tenant, 'instagram', ['username' => 'maha']);
    candidateConnection($tenant, 'facebook', ['url' => 'javascript:alert(1)']);
    ownerServiceItem($tenant->id, ['title' => 'Haircut']);

    $out = candidatesFor($tenant);

    expect(collect($out)->pluck('id')->all())->not->toContain('platform:facebook');
    foreach ($out as $c) {
        expect(ActionId::isValid($c['id']))->toBeTrue();
        expect(str_starts_with($c['url'], '/') || UrlSafety::safeHref($c['url']) === $c['url'])->toBeTrue();
    }
});
