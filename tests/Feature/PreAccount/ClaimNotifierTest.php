<?php

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimNotifier;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('emails the claim link when a build has a contact_email', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => 'lead@example.com']);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());

    Mail::assertQueued(ClaimInviteMail::class, fn ($m) =>
        $m->recipientEmail === 'lead@example.com'
        && $m->claimUrl === 'https://app.partna.au/claim/janedoe');
});

it('sends no email when contact_email is null', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'janedoe']);
    $build = PreAccountBuild::factory()->make(['contact_email' => null]);
    $build->user()->associate($user);
    $build->save();

    app(ClaimNotifier::class)->notify($build->fresh());

    Mail::assertNothingQueued();
});
