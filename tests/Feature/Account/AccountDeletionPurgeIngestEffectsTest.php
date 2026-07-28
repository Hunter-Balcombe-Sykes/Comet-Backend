<?php

use App\Models\Core\User\User;
use App\Services\User\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

// #PRIV-1: ingest.effects.meta stores the vendor result INLINE up to 1 MB
// (EffectLedger::once()), so a google-business effect's meta carries verbatim
// reviewer names and text — third-party PII with no other erasure path. The FK
// added by migration 20260729120002 is ON DELETE SET NULL (a money ledger),
// not CASCADE, so erasure must be explicit: AccountDeletionService::purge()'s
// purgeIngestEffects() step, covered here.

beforeEach(function () {
    AccountDeletionTestCase::boot();

    config([
        'partna.media_disk' => 'media',
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-key',
    ]);

    Storage::fake('media');
    Http::fake(['https://test.supabase.co/auth/v1/admin/users/*' => Http::response('', 200)]);
});

function seedIngestEffectsPurgeUser(string $originalEmail): array
{
    $id = (string) Str::uuid();
    $authId = (string) Str::uuid();
    $handle = 'ie-'.substr($id, 0, 6);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authId,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'Ingest Effects PII User',
        'primary_email' => "deleted+{$id}@partna.au", // already pseudonymised
        'status' => 'pending_deletion',
        'deletion_confirmed_at' => now()->subDays(31)->toIso8601String(),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $id,
        'professional_handle_snapshot' => $handle,
        'professional_email_snapshot' => $originalEmail,
        'event' => 'confirmed',
        'actor_type' => 'professional',
        'created_at' => now()->subDays(31)->toIso8601String(),
    ]);

    return ['id' => $id, 'auth_user_id' => $authId, 'email' => $originalEmail];
}

function seedIngestSource(string $userId): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('ingest.sources')->insert([
        'id' => $id,
        'user_id' => $userId,
        'source_key' => 'google-business',
        'surface_key' => 'reviews',
        'identifier' => 'place-'.substr($id, 0, 8),
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function seedIngestEffect(string $sourceId, array $overrides = []): string
{
    $digest = $overrides['digest'] ?? (string) Str::uuid();

    DB::connection('pgsql')->table('ingest.effects')->insert(array_merge([
        'digest' => $digest,
        'source_id' => $sourceId,
        'kind' => 'http',
        'cost_units' => 1,
        'claimed_at' => now()->toIso8601String(),
        'settled_at' => now()->toIso8601String(),
        'status' => 'ok',
        'meta' => json_encode(['result' => ['reviews' => [['author_name' => 'A Reviewer']]]]),
    ], $overrides));

    return (string) $digest;
}

it('deletes ingest.effects rows for the purged professional\'s ingest.sources (#PRIV-1)', function () {
    $user = seedIngestEffectsPurgeUser('ie-priv1@example.com');
    $sourceId = seedIngestSource($user['id']);

    seedIngestEffect($sourceId);
    seedIngestEffect($sourceId);

    $professional = User::find($user['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    $count = DB::connection('pgsql')->table('ingest.effects')
        ->where('source_id', $sourceId)->count();

    expect($count)->toBe(0);
});

it('does not delete another professional\'s ingest.effects rows (#PRIV-1)', function () {
    $target = seedIngestEffectsPurgeUser('ie-priv1-target@example.com');
    $other = seedIngestEffectsPurgeUser('ie-priv1-other@example.com');

    $targetSourceId = seedIngestSource($target['id']);
    $otherSourceId = seedIngestSource($other['id']);

    seedIngestEffect($targetSourceId);
    $survivorDigest = seedIngestEffect($otherSourceId);

    $professional = User::find($target['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    expect(
        DB::connection('pgsql')->table('ingest.effects')->where('source_id', $targetSourceId)->count()
    )->toBe(0);

    $survivor = DB::connection('pgsql')->table('ingest.effects')->where('digest', $survivorDigest)->first();
    expect($survivor)->not->toBeNull();
    expect($survivor->source_id)->toBe($otherSourceId);
});

it('is a no-op when the professional has no ingest.sources rows', function () {
    $user = seedIngestEffectsPurgeUser('ie-priv1-nosources@example.com');

    $professional = User::find($user['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();
});

it('reports (does not silently drop) a non-null body_ref before deleting the row', function () {
    // body_ref has no writer today (see EffectLedger — the P7 off-row drivers
    // are unbuilt). If it is EVER populated, this must page loudly rather than
    // silently under-erase the referenced off-row response body.
    $user = seedIngestEffectsPurgeUser('ie-priv1-bodyref@example.com');
    $sourceId = seedIngestSource($user['id']);
    seedIngestEffect($sourceId, ['body_ref' => 'ingest/private/some-response-body.json']);

    $fake = Exceptions::fake();

    $professional = User::find($user['id']);
    $result = app(AccountDeletionService::class)->purge($professional);

    expect($result)->toBeTrue();

    // The row is still deleted — reporting the gap is not a reason to leave
    // the effect row (and its inline PII) behind.
    expect(DB::connection('pgsql')->table('ingest.effects')->where('source_id', $sourceId)->count())->toBe(0);

    $reported = collect($fake->reported())->filter(
        fn ($e) => $e instanceof RuntimeException && str_contains($e->getMessage(), 'purgeIngestEffects')
    );
    expect($reported)->toHaveCount(1);
});
