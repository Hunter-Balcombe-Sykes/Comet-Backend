<?php

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
    Queue::assertPushed(App\Jobs\PreAccount\GeneratePreAccountSiteJob::class);
});
