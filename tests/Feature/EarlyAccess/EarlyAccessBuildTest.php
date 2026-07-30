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

it('creates a dark early-access build and links the signup on first signup', function () {
    $this->postJson('/api/public/early-access', [
        'email' => 'lead@example.com', 'type' => 'partna',
        'platforms' => ['instagram', 'tiktok'],
        'source_type' => 'instagram', 'source_ref' => 'ea_handle',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ])->assertOk();

    $signup = EarlyAccessSignup::firstOrFail();
    expect($signup->user_id)->not->toBeNull()
        ->and($signup->source_ref)->toBe('ea_handle');

    $build = PreAccountBuild::where('user_id', $signup->user_id)->firstOrFail();
    expect($build->built_via)->toBe('early_access')
        ->and($build->expires_at)->toBeNull()
        ->and($build->contact_email)->toBe('lead@example.com');
    Queue::assertPushed(GeneratePreAccountSiteJob::class);
});

it('still captures the lead and persists the submitted source when the handle is malformed', function () {
    // Instagram refs must match ^[a-z0-9._]{1,30}$ — this fails normalizeRef,
    // so requestBuild throws SOURCE_REF_INVALID and the try/catch swallows it.
    // The uniform success response + the row's source columns must not depend
    // on the build succeeding.
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
    // contact_email — NOT email-gated). requestBuild's dedupe re-serves this
    // exact row for the early-access request below; linking it would silently
    // defeat the email gate, so it must stay unlinked instead.
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

    // The dedupe re-served the existing build (no new row) and left it untouched.
    expect(PreAccountBuild::count())->toBe(1);
    $existingBuild->refresh();
    expect($existingBuild->contact_email)->toBeNull()
        ->and($existingBuild->built_via)->toBe(PreAccountBuild::VIA_SIGNUP);
});
