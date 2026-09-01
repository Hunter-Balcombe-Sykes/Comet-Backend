<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Jobs\PreAccount\PrewarmInstagramProfileJob;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Services\PreAccount\BuildProgress;
use App\Services\PreAccount\ClaimSiteService;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\PreAccount\SourcePrefetch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    // The setup progress ledger the poll reads on every call (2026-09-02).
    setupPreAccountBuildEventsTable();
    // LIFE-2: requestBuild() now takes a pg_advisory_xact_lock inside the build
    // transaction for every signup-path build (no staff actor) — without the shim
    // this errors on SQLite (no such function).
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('accepts a valid signup build and returns 202 with a build id', function () {
    $res = $this->postJson('/api/public/signup/build', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => '@JaneDoe',
    ]);

    $res->assertStatus(202)->assertJsonStructure(['build_id', 'build_state']);
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
});

it('re-serves an existing live build with 200 and its original account_type', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])->assertStatus(202);

    $res = $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'JaneDoe']);
    $res->assertStatus(200)->assertJsonPath('account_type', 'partna');
});

it('rejects a bad pairing with 422', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'google_business', 'source_ref' => 'ChIJx', 'source_name' => 'Cafe'])
        ->assertStatus(422)->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
});

it('requires source_name for google_business builds', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'business', 'source_type' => 'google_business', 'source_ref' => 'ChIJx'])
        ->assertStatus(422);
});

it('403s with WAITLIST_ONLY when the waitlist gate is on (moved from bootstrap)', function () {
    config(['partna.waitlist.enabled' => true]);
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe'])
        ->assertStatus(403)->assertJsonPath('code', 'WAITLIST_ONLY');
});

it('polls a build through its lifecycle: subdomain available immediately (claim needs it before ready), site_url only once ready', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe']);
    $build = PreAccountBuild::firstOrFail();
    // Item 1a: the subdomain now exists from MATERIALIZATION (seconds into the
    // job, still well before ready) rather than from the 202 itself — Decision
    // A (claim before ready) holds, one poll-tick later.
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $subdomain = $build->user->site->subdomain;

    // Subdomain exists from creation (SiteProvisioningService::createSiteWithRetry
    // runs synchronously in requestBuild(), long before build_state reaches
    // 'ready') and is guessable-by-design per spec — no reason to withhold it.
    // The frontend needs it here, pre-ready, to call POST /api/claim (Decision
    // A: claim no longer waits for ready). site_url stays ready-gated — that's
    // the "go visit a real site" signal, appropriately withheld until there's
    // actual content.
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('build_state', 'pending')
        ->assertJsonPath('subdomain', $subdomain)
        ->assertJsonMissingPath('site_url');

    $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save(); // B11 SEC-4
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('subdomain', $subdomain)
        ->assertJsonPath('site_url', fn ($url) => is_string($url) && str_contains($url, $subdomain));
});

it('stays reachable and correct after the build has been claimed — no new authenticated endpoint needed', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'janedoe']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save(); // B11 SEC-4: build_state no longer fillable

    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable();
    $subdomain = $build->user->site->subdomain;
    app(ClaimSiteService::class)->claim('auth-uid-1', 'jane@example.com', $subdomain);

    // Same opaque-UUID, unauthenticated response shape as pre-claim — the
    // dashboard can keep polling this exact endpoint by the build_id it
    // already holds from step 2 of signup instead of needing a new
    // authenticated status endpoint.
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('build_state', 'ready')
        ->assertJsonPath('subdomain', $subdomain);
});

it('404s an unknown build id (public enumeration-safe)', function () {
    $this->getJson('/api/public/signup/builds/'.Str::uuid())->assertStatus(404);
});

// #PRIV-3: the wire pin for PreAccountBuild::hashIp(). The unit tests cover the
// helper; this covers the ONE call site that feeds it — a revert to a bare
// sha256 in the controller would otherwise be invisible from the endpoint.
it('stores a keyed HMAC of the visitor IP, never a bare sha256', function () {
    config(['partna.pre_account.ip_hash_key' => 'endpoint-pepper']);

    $this->withHeader('CF-Connecting-IP', '203.0.113.9')
        ->postJson('/api/public/signup/build', [
            'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'iphashwire',
        ])->assertStatus(202);

    $stored = PreAccountBuild::firstOrFail()->created_ip_hash;

    expect($stored)->toBe(hash_hmac('sha256', '203.0.113.9', 'endpoint-pepper'));
    expect($stored)->not->toBe(hash('sha256', '203.0.113.9'));
});

// ── #W2-SEC-1: claim_token minted on create, never on the wire elsewhere ────

it('returns a non-empty claim_token on a new build, and stores only its hash', function () {
    $res = $this->postJson('/api/public/signup/build', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'mintme',
    ])->assertStatus(202);

    $token = $res->json('claim_token');
    expect($token)->toBeString()->not->toBe('');

    $build = PreAccountBuild::firstOrFail();
    expect($build->claim_token_hash)->toBe(hash('sha256', $token));

    // The plaintext must not appear verbatim in ANY column of the row —
    // only the SHA-256 digest is ever persisted (ClaimTokenIssuer::issue()).
    foreach ($build->getAttributes() as $column => $value) {
        expect((string) $value)->not->toBe($token, "column '{$column}' leaked the plaintext token");
    }
});

it('returns NO claim_token on a dedupe re-serve, even from a different caller', function () {
    $this->withHeader('CF-Connecting-IP', '203.0.113.10')
        ->postJson('/api/public/signup/build', [
            'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'dedupeme',
        ])->assertStatus(202)->assertJsonPath('claim_token', fn ($t) => is_string($t) && $t !== '');

    $original = PreAccountBuild::firstOrFail();
    $originalHash = $original->claim_token_hash;

    // Different caller (different source IP), same source_ref → re-serve, not
    // a new build. Minting here would hand a working takeover capability to
    // anyone who can guess a live source_ref (spec §5.4).
    $res = $this->withHeader('CF-Connecting-IP', '203.0.113.11')
        ->postJson('/api/public/signup/build', [
            'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'dedupeme',
        ])->assertStatus(200);

    $res->assertJsonMissingPath('claim_token');
    expect(PreAccountBuild::firstOrFail()->claim_token_hash)->toBe($originalHash);
});

it('never surfaces claim_token on the public poll endpoint', function () {
    $this->postJson('/api/public/signup/build', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'pollme',
    ])->assertStatus(202)->assertJsonPath('claim_token', fn ($t) => is_string($t) && $t !== '');

    $build = PreAccountBuild::firstOrFail();

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonMissingPath('claim_token');
});

// ── 9h: tier markers on the poll wire ────────────────────────────────────────

it('stamps content_filled/enriched lazily on the poll once their conditions are observable', function () {
    setupSiteMediaTable();
    setupWorkplacesTable();
    // The marker's pool-item arm reads content.items/item_media — the plane
    // always exists in production, so the stand-ins exist here too.
    setupSectionsTables();
    setupContentTables();

    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'tierjane']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();

    // Pending: no tiers, nothing stamped.
    $this->getJson("/api/public/signup/builds/{$build->id}")->assertOk()->assertJsonMissingPath('tiers');
    expect($build->fresh()->content_filled_at)->toBeNull();

    // Ready but no content/workplace yet: still nothing.
    $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();
    $this->getJson("/api/public/signup/builds/{$build->id}")->assertOk()->assertJsonMissingPath('tiers');

    // Content lands (a READY content-pool asset) → next poll stamps content_filled.
    $site = $build->user->site;
    (new SiteMedia([
        'pool' => 'content', 'path' => 'images/t.webp', 'media_type' => 'image',
        'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => true,
    ]))->site()->associate($site)->save();

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('tiers.content_filled_at', fn ($v) => is_string($v));
    expect($build->fresh()->content_filled_at)->not->toBeNull()
        ->and($build->fresh()->enriched_at)->toBeNull();

    // Workplace lands → enriched stamps; content_filled stays at first observation.
    $first = $build->fresh()->content_filled_at;
    Workplace::forceCreate(['site_id' => (string) $site->id]);

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('tiers.enriched_at', fn ($v) => is_string($v));
    expect($build->fresh()->enriched_at)->not->toBeNull()
        ->and($build->fresh()->content_filled_at?->toIso8601String())->toBe($first?->toIso8601String());
});

it('stamps content_filled from a projected pool item with media — the Instagram pool serves before any site_media row exists', function () {
    setupSiteMediaTable();
    setupSectionsTables();
    setupContentTables();

    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'pooljane']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();

    // An item WITHOUT media is not content on the page yet.
    $bare = seedContentItem((string) $build->user_id);
    $this->getJson("/api/public/signup/builds/{$build->id}")->assertOk()->assertJsonMissingPath('tiers');
    expect($build->fresh()->content_filled_at)->toBeNull();

    // Its media row lands (the projection's item_media write) → the next poll
    // stamps content_filled, with no site_media row anywhere.
    DB::connection('pgsql')->table('content.item_media')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $bare, 'source_id' => 'src-1',
        'role' => 'cover', 'position' => 0, 'created_at' => now()->toDateTimeString(),
    ]);
    expect(SiteMedia::query()->count())->toBe(0);

    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('tiers.content_filled_at', fn ($v) => is_string($v));
    expect($build->fresh()->content_filled_at)->not->toBeNull();
});

// ── 9g: the scrape pre-warm endpoint ─────────────────────────────────────────

it('prewarm queues the cache-warm job and answers 202 without any existence signal', function () {
    $this->postJson('/api/public/signup/prewarm', ['source_type' => 'instagram', 'source_ref' => '@WarmJane'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'warming');

    Queue::assertPushed(PrewarmInstagramProfileJob::class, fn ($job) => $job->username === 'warmjane');
});

it('prewarm rejects a non-instagram source and a malformed ref', function () {
    $this->postJson('/api/public/signup/prewarm', ['source_type' => 'google_business', 'source_ref' => 'ChIJx'])
        ->assertStatus(422);
    $this->postJson('/api/public/signup/prewarm', ['source_type' => 'instagram', 'source_ref' => 'not a handle!'])
        ->assertStatus(422);

    Queue::assertNotPushed(PrewarmInstagramProfileJob::class);
});

// ── Setup progress (2026-09-02): the feed on the poll wire ───────────────────

it('carries a progress block from the first poll, fills it from the ledger, and reports done once content and the workplace question have landed', function () {
    setupSiteMediaTable();
    setupWorkplacesTable();
    setupSectionsTables();
    setupContentTables();
    setupPreAccountBuildEventsTable();

    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'feedjane']);
    $build = PreAccountBuild::firstOrFail();

    // Pending, empty ledger: still an answer.
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('progress.done', false)
        ->assertJsonPath('progress.stage', 'identity')
        ->assertJsonPath('progress.events', [])
        ->assertJsonPath('progress.media.total', 0);

    // Identity lands through materializeIdentity — the first ledger row.
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertOk()
        ->assertJsonPath('progress.events.0.stage', 'identity')
        ->assertJsonPath('progress.events.0.status', 'landed')
        ->assertJsonPath('progress.events.0.label', 'Found your Instagram')
        ->assertJsonPath('progress.stage', 'identity');

    // A user-keyed note reaches the same build while it is live and unclaimed.
    BuildProgress::noteForUser((string) $build->user_id, PreAccountBuildEvent::STAGE_WORKPLACE, PreAccountBuildEvent::STATUS_STARTED, 'Checking 2 places mentioned in your bio');
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertJsonPath('progress.stage', 'workplace')
        ->assertJsonCount(2, 'progress.events');

    // Ready + content, but the workplace question still open: not done.
    $build->forceFill(['build_state' => PreAccountBuild::STATE_READY])->save();
    $site = $build->user->site;
    (new SiteMedia(['pool' => 'content', 'path' => 'images/t.webp', 'media_type' => 'image', 'processing_state' => 'ready', 'sort_order' => 0, 'is_active' => true]))->site()->associate($site)->save();
    $this->getJson("/api/public/signup/builds/{$build->id}")->assertJsonPath('progress.done', false);

    // The chain says skipped — the workplace question is answered, but an
    // Instagram build still owes the platforms answer.
    BuildProgress::noteForUser((string) $build->user_id, PreAccountBuildEvent::STAGE_WORKPLACE, PreAccountBuildEvent::STATUS_SKIPPED, 'No workplace mentioned in your bio — you can add one later');
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertJsonPath('progress.done', false)
        ->assertJsonPath('progress.stage', 'workplace');

    // The seeder always answers it, even with nothing to connect → done
    // (no media assets to save on this build).
    BuildProgress::noteForUser((string) $build->user_id, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_SKIPPED, 'No links in your bio to connect — add platforms from the dashboard');
    $this->getJson("/api/public/signup/builds/{$build->id}")
        ->assertJsonPath('progress.done', true)
        ->assertJsonPath('progress.stage', 'platforms');
});

it('noteForUser writes nothing for a claimed or stale build, and note() never throws', function () {
    setupPreAccountBuildEventsTable();

    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'quietjane']);
    $build = PreAccountBuild::firstOrFail();
    app(PreAccountBuildService::class)->materializeIdentity($build, new SourcePrefetch(payload: []));
    $build->refresh();
    expect(PreAccountBuildEvent::query()->where('build_id', $build->id)->count())->toBe(1);

    $build->forceFill(['claimed_at' => now()])->save();
    BuildProgress::noteForUser((string) $build->user_id, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_LANDED, 'Menu: 3 dishes');
    expect(PreAccountBuildEvent::query()->where('build_id', $build->id)->count())->toBe(1);

    $build->forceFill(['claimed_at' => null, 'created_at' => now()->subHours(2)])->save();
    BuildProgress::noteForUser((string) $build->user_id, PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_LANDED, 'Menu: 3 dishes');
    expect(PreAccountBuildEvent::query()->where('build_id', $build->id)->count())->toBe(1);

    // A ledger write that cannot succeed (no such build) is reported, not thrown.
    BuildProgress::note((string) Str::uuid(), PreAccountBuildEvent::STAGE_MENU, PreAccountBuildEvent::STATUS_LANDED, 'orphan');
    expect(true)->toBeTrue();
});
