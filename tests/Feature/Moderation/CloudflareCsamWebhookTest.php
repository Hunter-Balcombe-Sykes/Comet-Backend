<?php

use App\Models\Moderation\CsamQuarantine;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Models\Moderation\NcmecSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    Redis::flushdb();
    config(['partna.moderation.csam.cloudflare_webhook_secret' => 'test-secret']);
});

/**
 * Post a signed Cloudflare CSAM webhook payload.
 * Signature format mirrors VerifyCloudflareWebhookSignature: t=<ts>,v1=<hmac>.
 */
function postSignedCsamWebhook(\Tests\TestCase $test, array $payload): \Illuminate\Testing\TestResponse
{
    $ts   = time();
    $body = json_encode($payload);
    $sig  = hash_hmac('sha256', "{$ts}.{$body}", 'test-secret');

    return $test->call(
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
}

/**
 * Create a minimal user + site row and return their IDs.
 * Named with Csam prefix to avoid collision with the identical helper
 * defined in BackfillLinkCategoriesTest.php.
 *
 * @return array{0: string, 1: string} [userId, siteId]
 */
function createCsamTestTenant(): array
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now    = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id'           => $userId,
        'auth_user_id' => 'auth-' . Str::random(12),
        'handle'       => 'csam-fixture-' . Str::random(6),
        'handle_lc'    => 'csam-fixture-' . strtolower(Str::random(6)),
        'display_name' => 'CSAM Fixture User',
        'primary_email' => 'csam-' . Str::random(6) . '@example.test',
        'account_type' => 'individual',
        'status'       => 'active',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id'           => $siteId,
        'user_id'      => $userId,
        'subdomain'    => 'csam-fixture-' . Str::random(6),
        'is_published' => 1,
        'settings'     => json_encode([]),
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    return [$userId, $siteId];
}

it('handles a valid CSAM match webhook: case + quarantine + ncmec_submission + decision created', function () {
    Queue::fake();
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
    setupAllModerationTables();

    [$userId, $siteId] = createCsamTestTenant();

    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id'               => $mediaId,
        'site_id'          => $siteId,
        'path'             => 'quarantine/m.jpg',
        'processing_state' => 'scanning',
        'created_at'       => now()->toDateTimeString(),
        'updated_at'       => now()->toDateTimeString(),
    ]);

    $payload = [
        'match_id'        => 'cf-match-001',
        'r2_key'          => 'quarantine/m.jpg',
        'matched_hash'    => str_repeat('a', 64),
        'confidence'      => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $res = postSignedCsamWebhook($this, $payload);
    $res->assertStatus(200);

    expect(ModerationCase::query()->where('case_type', 'csam_match')->count())->toBe(1);
    expect(CsamQuarantine::query()->count())->toBe(1);
    expect(NcmecSubmission::query()->count())->toBe(1);
    expect(Decision::query()->where('decided_by_system', true)->count())->toBe(1);

    // Verify IDs are returned in the response body.
    $data = $res->json();
    expect($data)->toHaveKeys(['case_id', 'decision_id', 'quarantine_id']);
})->group('postgres');

it('returns 404 when r2_key does not map to any site_media row', function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteMediaTable();
    setupAllModerationTables();

    $payload = [
        'match_id'        => 'orphan',
        'r2_key'          => 'quarantine/does-not-exist.jpg',
        'matched_hash'    => str_repeat('b', 64),
        'confidence'      => 'exact',
        'matched_against' => 'NCMEC-NetClean',
    ];

    $res = postSignedCsamWebhook($this, $payload);
    $res->assertStatus(404);
})->group('postgres');
