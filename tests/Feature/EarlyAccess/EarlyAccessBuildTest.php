<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
    setupEmailSubscriptionsTable();
    Queue::fake();
});

it('captures the lead WITHOUT building — an anonymous form may not reserve a handle', function () {
    // REVERSED 2026-08-24 (owner decision). This test used to assert that one
    // anonymous POST created a linked, never-expiring early-access build. That
    // WAS the behaviour, and it was a permanent handle squat: the path passed
    // ipHash:null (skipping the per-IP cap, which guards on
    // `$staff === null && $ipHash !== null`) and built_via:EARLY_ACCESS
    // (expires_at NULL, which PruneExpiredPreAccountBuilds never prunes). The
    // handle seed is the caller's own source_ref, so a stranger got the
    // victim's exact subdomain with the stranger's unverified address as
    // contact_email — and the real owner's later claim died on
    // CLAIM_EMAIL_MISMATCH, permanently.
    //
    // Building now happens in ApproveEarlyAccessBuildJob, behind staff
    // approval. The lead itself must still be captured in full.
    $this->postJson('/api/public/early-access', [
        'email' => 'lead@example.com', 'type' => 'partna',
        'platforms' => ['instagram', 'tiktok'],
        'source_type' => 'instagram', 'source_ref' => 'ea_handle',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ])->assertOk();

    $signup = EarlyAccessSignup::firstOrFail();
    // The lead, and everything staff need to build it later, is persisted.
    expect($signup->source_type)->toBe('instagram')
        ->and($signup->source_ref)->toBe('ea_handle')
        ->and($signup->status)->toBe(EarlyAccessSignup::STATUS_WAITLIST);

    // No handle reserved, no site, no scrape queued.
    expect($signup->user_id)->toBeNull()
        ->and(PreAccountBuild::count())->toBe(0);
    Queue::assertNotPushed(GeneratePreAccountSiteJob::class);
});

it('cannot be used twice to squat two handles, because it never builds at all', function () {
    // The squat needed a build per POST. With none, volume buys nothing but
    // waitlist rows — which is what a marketing form is for.
    foreach (['joespizza', 'sallysalon', 'bobsbarber'] as $i => $ref) {
        $this->postJson('/api/public/early-access', [
            'email' => "squatter{$i}@example.com", 'type' => 'partna',
            'platforms' => ['instagram', 'tiktok'],
            'source_type' => 'instagram', 'source_ref' => $ref,
            'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
        ])->assertOk();
    }

    expect(EarlyAccessSignup::count())->toBe(3)
        ->and(PreAccountBuild::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

it('still captures the lead and persists the submitted source when the handle is malformed', function () {
    // Still true, for a simpler reason since 2026-08-24: this endpoint no
    // longer builds at all, so a malformed ref cannot fail a build it never
    // attempts. The row's source columns are persisted verbatim either way —
    // staff correct the ref at approval time, where a human is present to
    // answer "whose handle is this?".
    $this->postJson('/api/public/early-access', [
        'email' => 'lead-bad-handle@example.com', 'type' => 'partna',
        'platforms' => ['instagram', 'tiktok'],
        'source_type' => 'instagram', 'source_ref' => 'has spaces!',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ])->assertOk();

    $signup = EarlyAccessSignup::firstOrFail();
    expect($signup->source_type)->toBe('instagram')
        ->and($signup->source_ref)->toBe('has spaces!')
        ->and($signup->user_id)->toBeNull();

    expect(PreAccountBuild::count())->toBe(0);
});

it('does not link the signup when the source ref collides with an existing non-early-access build', function () {
    // Pre-existing Flow-1/2 build for this handle (signup-originated, no
    // contact_email). This used to be a live hazard: requestBuild's dedupe
    // re-served this exact row to the early-access caller, and linking it
    // would have silently defeated the email gate. Since the endpoint stopped
    // building (2026-08-24) the collision cannot arise — the assertions below
    // now pin that the existing row is left COMPLETELY untouched by a
    // marketing submission naming the same handle.
    $existingUser = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $existingUser->id, 'is_published' => false]);
    $existingBuild = PreAccountBuild::factory()->make([
        'source_type' => 'instagram',
        'source_ref' => 'collidehandle',
        'source_ref_lc' => 'collidehandle',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'build_state' => PreAccountBuild::STATE_READY,
        'contact_email' => null,
    ]);
    $existingBuild->user()->associate($existingUser);
    $existingBuild->save();

    $this->postJson('/api/public/early-access', [
        'email' => 'collide@example.com', 'type' => 'partna',
        'platforms' => ['instagram', 'tiktok'],
        'source_type' => 'instagram', 'source_ref' => 'collidehandle',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ])->assertOk();

    $signup = EarlyAccessSignup::where('email_lc', 'collide@example.com')->firstOrFail();
    expect($signup->user_id)->toBeNull();

    // No new row, and the pre-existing one is not adopted, re-gated or re-dated.
    expect(PreAccountBuild::count())->toBe(1);
    $existingBuild->refresh();
    expect($existingBuild->contact_email)->toBeNull()
        ->and($existingBuild->built_via)->toBe(PreAccountBuild::VIA_SIGNUP);
});
