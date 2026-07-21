<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\PreAccountBuild;
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
    ])->assertOk();

    $signup = EarlyAccessSignup::firstOrFail();
    expect($signup->source_type)->toBe('instagram')
        ->and($signup->source_ref)->toBe('has spaces!')
        ->and($signup->user_id)->toBeNull();

    expect(PreAccountBuild::count())->toBe(0);
});
