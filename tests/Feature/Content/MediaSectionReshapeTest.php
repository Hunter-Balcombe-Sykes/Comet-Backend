<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 1a §3.6: ten dev pool:media sections carry the watch/listen default
// (latest_per_auto_source ⇒ ONE photo per connection). The constant change
// fixes new provisions; this command fixes the existing rows — matched on
// rule CONTENT, so a hand-edited section is not clobbered.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function legacyMediaSection(string $siteId): string
{
    // Exactly what PoolSectionProvisioner wrote before this slice.
    $site = Site::query()->findOrFail($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, 'media');
    DB::table('site.sections')->where('id', $section->id)->update([
        'rule' => json_encode(['all' => [
            ['op' => 'kind_is', 'values' => ['media']],
            ['op' => 'latest_per_auto_source', 'values' => ['media']],
        ]]),
    ]);

    return (string) $section->id;
}

it('reshapes a section still carrying latest_per_auto_source and fires all three cache lanes', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    // sites.updated_at is second-precision (Grammar truncates DateTimeInterface
    // bindings to Y-m-d H:i:s) — this whole test runs well under a second, so
    // without an explicit clock advance $before and the command's write land
    // in the same second and the not->toBe() below is a coin flip, not a check.
    $this->travel(1)->second();

    $this->artisan('content:reshape-pool-sections', ['pool' => 'media'])
        ->expectsOutputToContain('reshaped 1')
        ->assertExitCode(0);

    $rule = json_decode((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'), true);
    expect($rule)->toBe(['all' => [['op' => 'kind_is', 'values' => ['media']], ['op' => 'latest_n_per_auto_source', 'values' => ['media']]]])
        // BuildState::bump() persists as a counter on site.site_build_state
        // (app/Site/Documents/BuildState.php:24-38), not a row insert into
        // site.site_documents — that table is DocumentBuilder's own output
        // and this command never touches it. content_revision starting from
        // "no row yet" lands at 1 after exactly one bump().
        ->and(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))->toBe(1)
        ->and(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('leaves a hand-edited rule alone, and reports it', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $custom = json_encode(['all' => [['op' => 'kind_is', 'values' => ['media']], ['op' => 'headline_has', 'values' => ['dog']]]]);
    DB::table('site.sections')->where('id', $sectionId)->update(['rule' => $custom]);

    $this->artisan('content:reshape-pool-sections', ['pool' => 'media'])->assertExitCode(0);

    expect((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'))->toBe($custom);
});

it('writes nothing under --dry-run', function () {
    [$pro, $siteId] = poolTenant();
    $sectionId = legacyMediaSection($siteId);
    $before = (string) DB::table('site.sections')->where('id', $sectionId)->value('rule');

    $this->artisan('content:reshape-pool-sections', ['pool' => 'media', '--dry-run' => true])->assertExitCode(0);

    expect((string) DB::table('site.sections')->where('id', $sectionId)->value('rule'))->toBe($before);
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('resolves EVERY media item under the corrected rule, not one per source', function () {
    // The spec's warning: assert against the RESOLVER, not just the constant —
    // a rule the executor does not recognise fails at runtime, not in the registry.
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id, 'google_business.location');
    $sourceId = poolSource($pro->id, $connectionId);
    $one = poolItem($pro->id, $sourceId, 'media', 'Photo 1', '2026-08-01T00:00:00Z');
    $two = poolItem($pro->id, $sourceId, 'media', 'Photo 2', '2026-08-02T00:00:00Z');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $selectedIds = collect($out['selection'])->pluck('id')->all();

    // Under latest_per_auto_source this would be ONE item. kind_is admits both.
    expect($selectedIds)->toContain($one)->toContain($two);
});

it('publishes only the N newest per source under the opt-in media rule, and none from a photos-off Google source (R5)', function () {
    config(['partna.pools.auto_latest_n' => 2]);
    [$pro, $siteId] = poolTenant();
    $ig = poolSource($pro->id, poolConnection($pro->id, 'instagram.profile'));
    $gbOff = poolSource($pro->id, poolConnection($pro->id, 'google_business.location', ['photos' => false]));
    poolItem($pro->id, $ig, 'media', 'IG old', '2026-08-01T00:00:00Z');
    $igMid = poolItem($pro->id, $ig, 'media', 'IG mid', '2026-08-02T00:00:00Z');
    $igNew = poolItem($pro->id, $ig, 'media', 'IG new', '2026-08-03T00:00:00Z');
    poolItem($pro->id, $gbOff, 'media', 'GB hidden', '2026-08-04T00:00:00Z');

    $data = poolGet($pro, 'media');
    $ids = array_column($data['selection'], 'id');
    sort($ids);
    $expected = [$igMid, $igNew];
    sort($expected);
    expect($ids)->toBe($expected)
        ->and(count($data['library']))->toBe(4);
});

it('reshapes stale kind_is shop sections to the pins+latest default (2026-08-17 shape)', function () {
    [$pro, $siteId] = poolTenant();
    $site = Site::query()->findOrFail($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, 'shop');
    DB::table('site.sections')->where('id', $section->id)->update([
        'rule' => json_encode(['all' => [['op' => 'kind_is', 'values' => ['product']]]]),
    ]);

    $this->artisan('content:reshape-pool-sections', ['pool' => 'shop'])->expectsOutputToContain('reshaped 1')->assertExitCode(0);
    $rule = json_decode((string) DB::table('site.sections')->where('id', $section->id)->value('rule'), true);
    expect($rule)->toBe(['all' => \App\Site\Pools\PoolRegistry::sectionShape('shop')['rule']]);
});
