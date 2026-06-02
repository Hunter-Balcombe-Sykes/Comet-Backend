<?php

// tests/Feature/Analytics/AsyncIngestContractTest.php

use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// These assert the ASYNC contract, so they force the QueuedIngestor (the default
// testing binding is SyncIngestor, which writes inline). With the queue faked, the job
// is captured but never run — so we assert dispatch behaviour, not rows.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    app()->bind(AnalyticsIngestor::class, QueuedIngestor::class);
    Queue::fake();
});

it('accepts a pageview, echoes a visit_id, and enqueues a job', function () {
    $t = createBrandTenant('contract-pv');

    $res = $this->postJson('/api/public/analytics/pageviews', ['site_id' => $t->site->id]);

    $res->assertStatus(201)->assertJsonStructure(['message', 'visit_id']);
    Queue::assertPushed(RecordAnalyticsEventJob::class, fn ($j) => $j->queue === 'analytics');
});

it('records a pageview even for a bot UA (no bot filter on pageview — preserved)', function () {
    $t = createBrandTenant('contract-pv-bot');

    $this->withHeader('User-Agent', 'Googlebot/2.1')
        ->postJson('/api/public/analytics/pageviews', ['site_id' => $t->site->id])
        ->assertStatus(201);

    Queue::assertPushed(RecordAnalyticsEventJob::class);
});

it('accepts a click to a non-existent block (201, not 422) and enqueues — worker drops', function () {
    $t = createBrandTenant('contract-click-missing');

    $res = $this->postJson('/api/public/analytics/clicks', [
        'site_id' => $t->site->id,
        'block_id' => (string) Str::uuid(), // valid uuid, no row
        'visitor_id' => (string) Str::uuid(),
    ]);

    $res->assertStatus(201)->assertJsonStructure(['message', 'click_id']);
    Queue::assertPushed(RecordAnalyticsEventJob::class);
});

it('bot click returns 200 with no click_id and enqueues nothing', function () {
    $t = createBrandTenant('contract-click-bot');
    $block = createLinkBlockFor($t);

    $res = $this->withHeader('User-Agent', 'Googlebot/2.1')
        ->postJson('/api/public/analytics/clicks', [
            'site_id' => $t->site->id, 'block_id' => $block->id, 'visitor_id' => (string) Str::uuid(),
        ]);

    $res->assertStatus(200)->assertJsonMissing(['click_id' => true]);
    Queue::assertNothingPushed();
});

it('dedups a repeat click, echoing the original id and enqueuing once', function () {
    $t = createBrandTenant('contract-click-dedup');
    $block = createLinkBlockFor($t);
    $payload = ['site_id' => $t->site->id, 'block_id' => $block->id, 'visitor_id' => (string) Str::uuid()];

    $first = $this->postJson('/api/public/analytics/clicks', $payload);
    $second = $this->postJson('/api/public/analytics/clicks', $payload);

    $first->assertStatus(201);
    $second->assertStatus(201);
    expect($second->json('click_id'))->toBe($first->json('click_id'));
    Queue::assertPushed(RecordAnalyticsEventJob::class, 1);
});

it('preserves the 422 IDOR signal when site_id is supplied with a mismatched subdomain', function () {
    $t = createBrandTenant('contract-idor');

    $res = $this->postJson('/api/public/analytics/pageviews', [
        'site_id' => $t->site->id,
        'subdomain' => 'someone-elses-handle',
    ]);

    $res->assertStatus(422);
    Queue::assertNothingPushed();
});
