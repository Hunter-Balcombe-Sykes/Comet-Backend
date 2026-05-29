<?php

use App\Jobs\Moderation\FileCyberTipReportJob;
use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    Redis::flushdb();
    config([
        'partna.moderation.csam.cloudflare_webhook_secret' => 'test',
        'partna.moderation.csam.ncmec_endpoint' => 'https://hashmatching.api.missingkids.org/cybertip',
        'partna.moderation.csam.ncmec_api_key'  => 'test',
        'partna.moderation.csam.ncmec_esp_id'   => 'ESP',
    ]);
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
    setupAllModerationTables();
});

/**
 * Create a minimal tenant (user + site) with all required columns for the
 * CSAM pipeline. Defined locally to avoid relying on load order of other test files.
 *
 * @return array{0: string, 1: string} [userId, siteId]
 */
function createCsamPipelineTestTenant(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now    = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id'            => $userId,
        'auth_user_id'  => 'auth-' . Str::random(12),
        'handle'        => 'pipeline-fixture-' . Str::random(6),
        'handle_lc'     => 'pipeline-fixture-' . strtolower(Str::random(6)),
        'display_name'  => 'Pipeline Fixture User',
        'primary_email' => 'pipeline-' . Str::random(6) . '@example.test',
        'account_type'  => 'individual',
        'status'        => 'active',
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'           => $siteId,
        'user_id'      => $userId,
        'subdomain'    => 'pipeline-fixture-' . Str::random(6),
        'is_published' => 1,
        'settings'     => json_encode([]),
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    return [$userId, $siteId];
}

it('webhook → full auto-action: case + decision + quarantine + outbox + jobs dispatched', function () {
    // ModerationActionDispatcher for suspend_user dispatches:
    //   SuspendUserJob, SuspendSiteJob, PurgeModerationCacheJob (sync_subdomain_kv),
    //   NotifyReportedUserJob.
    // CsamMatchHandlerService dispatches FileCyberTipReportJob afterCommit.
    Bus::fake([
        SuspendUserJob::class,
        SuspendSiteJob::class,
        PurgeModerationCacheJob::class,
        NotifyReportedUserJob::class,
        FileCyberTipReportJob::class,
    ]);

    [$userId, $siteId] = createCsamPipelineTestTenant();

    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $siteId,
        'path'             => 'quarantine/auto.jpg',
        'processing_state' => 'scanning',
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    $payload = [
        'match_id'        => 'auto-001',
        'r2_key'          => 'quarantine/auto.jpg',
        'matched_hash'    => str_repeat('c', 64),
        'confidence'      => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $ts   = time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", 'test');

    $res = $this->call(
        'POST',
        '/api/v1/internal/cloudflare-csam-webhook',
        [],
        [],
        [],
        [
            'HTTP_CF_WEBHOOK_TIMESTAMP' => (string) $ts,
            'HTTP_CF_WEBHOOK_SIGNATURE' => "t={$ts},v1={$sig}",
            'CONTENT_TYPE'             => 'application/json',
        ],
        $body,
    );

    $res->assertStatus(200);

    expect(ModerationCase::query()->where('case_type', 'csam_match')->count())->toBe(1);
    expect(CsamQuarantine::query()->where('site_media_id', $mediaId)->count())->toBe(1);
    expect(NcmecSubmission::query()->count())->toBe(1);
    expect(Decision::query()->where('decided_by_system', true)->where('auto_actioned', true)->count())->toBe(1);

    Bus::assertDispatched(SuspendUserJob::class);
    Bus::assertDispatched(SuspendSiteJob::class);
    Bus::assertDispatched(PurgeModerationCacheJob::class);
    Bus::assertDispatched(NotifyReportedUserJob::class);
    Bus::assertDispatched(FileCyberTipReportJob::class);
})->group('postgres');
