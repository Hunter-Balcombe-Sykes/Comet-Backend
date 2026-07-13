<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Ranked-actions layer of analytics:compute-popularity — button derivation
// from the links source, within-kind normalization + prior/recency blend
// (deterministic fixtures), cold-start priors, and stale-key deletion.
// content_type='action' rows in analytics.content_popularity_scores, keyed
// '<kind>:<ref>'.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();     // sites + platform_connections
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupContentPopularityScoresTable();
});

function actionScoreRow(string $siteId, string $key): ?object
{
    return DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $siteId)
        ->where('content_type', 'action')
        ->where('content_key', $key)
        ->first();
}

/** All action rows for a site ordered by rank → list of content_keys. */
function actionOrder(string $siteId): array
{
    return DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $siteId)
        ->where('content_type', 'action')
        ->orderBy('rank')
        ->pluck('content_key')
        ->all();
}

function insertLinkBlock(object $tenant, array $attrs = []): string
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

function insertBookingSection(object $tenant, string $platform = 'fresha'): void
{
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'block_group' => 'sections',
        'block_type' => 'booking',
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([
            'booking_url' => 'https://fresha.com/book/x',
            'platform' => $platform,
            'title' => 'Book now',
        ]),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
}

it('derives buttons from platform links + synthesized booking, excluding custom links and home', function () {
    $tenant = createTenant('act-derive');
    insertLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);
    insertLinkBlock($tenant, ['category' => 'custom', 'platform' => null, 'title' => 'My thing', 'url' => 'https://thing.example', 'sort_order' => 1]);
    insertBookingSection($tenant);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    expect(actionScoreRow($tenant->site->id, 'button:instagram'))->not->toBeNull()
        ->and(actionScoreRow($tenant->site->id, 'button:booking'))->not->toBeNull()
        // Custom-category links are link_item ITEMS, never buttons.
        ->and(collect(actionOrder($tenant->site->id))->filter(fn ($k) => str_starts_with($k, 'button:'))->count())->toBe(2)
        // The lander IS home — no page:home action.
        ->and(actionScoreRow($tenant->site->id, 'page:home'))->toBeNull()
        // Live link blocks make the Links page present → a page action.
        ->and(actionScoreRow($tenant->site->id, 'page:links'))->not->toBeNull();
});

it('cold start: zero events yields a full prior+recency-ordered list (booking first)', function () {
    $tenant = createTenant('act-cold');
    insertBookingSection($tenant);                                     // button:booking  prior .95
    insertLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']); // button:instagram prior .70
    DB::connection('pgsql')->table('site.platform_connections')->insert([ // listen page present + fresh
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

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // Natives are all 0 (page freshness is subtracted from the native term) so
    // ordering = prior + recency. Recency 1.0 where an anchor exists (booking +
    // instagram blocks created now; listen's spotify connection created now);
    // the Services page (present via the live booking SECTION) has no connection/
    // service anchor → recency 0. Blends:
    //   button:booking   .25·.95 + .15·1 = .3875
    //   button:instagram .25·.70 + .15·1 = .3250
    //   page:listen      .25·.65 + .15·1 = .3125
    //   page:services    .25·.85 + 0    = .2125
    //   page:links       .25·.40 + 0    = .1000
    $order = actionOrder($tenant->site->id);
    expect($order[0])->toBe('button:booking')
        ->and($order[1])->toBe('button:instagram')
        ->and($order[2])->toBe('page:listen')
        ->and($order[3])->toBe('page:services')
        ->and($order[4])->toBe('page:links')
        ->and(count($order))->toBe(5);

    // Deterministic blend check (first run: blend with self is a no-op):
    // booking = .25*.95 + .15*1.0 = 0.3875 (native 0, recency 2^0).
    expect((float) actionScoreRow($tenant->site->id, 'button:booking')->score)
        ->toEqualWithDelta(0.3875, 0.005);
});

it('normalizes button clicks within kind — the clicked button rises, deterministically', function () {
    $tenant = createTenant('act-clicks');
    insertLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x', 'created_at' => now()->subDays(30)->toISOString()]);
    insertLinkBlock($tenant, ['platform' => 'youtube', 'title' => 'YouTube', 'url' => 'https://youtube.com/@x', 'sort_order' => 1, 'created_at' => now()->subDays(30)->toISOString()]);

    foreach (range(1, 10) as $i) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'platform' => 'instagram',
            'url' => 'https://instagram.com/x',
            'occurred_at' => now()->toISOString(),
            'created_at' => now()->toISOString(),
        ]);
    }
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'platform' => 'youtube',
        'url' => 'https://youtube.com/@x',
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    $instagram = actionScoreRow($tenant->site->id, 'button:instagram');
    $youtube = actionScoreRow($tenant->site->id, 'button:youtube');

    // instagram: norm 10/10=1.0 → .6·1 + .25·.70 + .15·2^(-30/14) = 0.8090
    // youtube:   norm 1/10=0.1 → .6·.1 + .25·.60 + .15·2^(-30/14) = 0.2440
    expect((int) $instagram->rank)->toBeLessThan((int) $youtube->rank)
        ->and((float) $instagram->score)->toEqualWithDelta(0.8090, 0.01)
        ->and((float) $youtube->score)->toEqualWithDelta(0.2440, 0.01);
});

it('scored items enter the pool when their hosting page is present', function () {
    $tenant = createTenant('act-items');
    // Active service → Book page present; item_views give the item a score row.
    $serviceId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.services')->insert([
        'id' => $serviceId,
        'user_id' => $tenant->id,
        'title' => 'Fade + beard trim',
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
    DB::connection('pgsql')->table('analytics.item_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'item_type' => 'service',
        'item_id' => $serviceId,
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    expect(actionScoreRow($tenant->site->id, 'item:service:'.$serviceId))->not->toBeNull()
        ->and(actionScoreRow($tenant->site->id, 'page:services'))->not->toBeNull();
});

it('deletes stale action keys when the pool shrinks', function () {
    $tenant = createTenant('act-stale');
    $blockId = insertLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);
    expect(actionScoreRow($tenant->site->id, 'button:instagram'))->not->toBeNull();

    // Deactivate the link → button leaves the pool AND the Links page loses
    // presence → both action rows must be deleted on the next run (the action
    // layer owns its lifecycle — absence IS the fade-out).
    DB::connection('pgsql')->table('site.blocks')->where('id', $blockId)->update(['is_active' => 0]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    expect(actionScoreRow($tenant->site->id, 'button:instagram'))->toBeNull()
        ->and(actionScoreRow($tenant->site->id, 'page:links'))->toBeNull();
});

it('keeps ranks stable under hysteresis blending across consecutive runs', function () {
    $tenant = createTenant('act-stable');
    insertBookingSection($tenant);
    insertLinkBlock($tenant, ['platform' => 'instagram', 'title' => 'Instagram', 'url' => 'https://instagram.com/x']);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);
    $first = actionOrder($tenant->site->id);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);
    $second = actionOrder($tenant->site->id);

    expect($second)->toBe($first);
});
