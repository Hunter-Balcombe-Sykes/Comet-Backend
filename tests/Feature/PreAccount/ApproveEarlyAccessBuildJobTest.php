<?php

use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimNotifier;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourceGeneratorRegistry;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
    config(['app.frontend_url' => 'https://app.partna.au']);
});

it('re-scrapes IG, opens the window, flips to invited, and emails the invite', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    // user_id is deliberately not $fillable on EarlyAccessSignup (B11 doctrine) —
    // create() would silently drop it, so link it via forceFill like the real
    // write path (EarlyAccessService::signupFromMarketing) does.
    $signup = EarlyAccessSignup::create([
        'email' => 'lead@example.com', 'email_lc' => 'lead@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate(User $user, Site $site, string $sourceRef): void {}
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($signup->fresh()->status)->toBe('invited');
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});

it('does not re-scrape a GBP early-access build, but still opens the window and invites', function () {
    Mail::fake();
    // GBP stays on the official-API refresh treadmill (spec §3.4): $needsScrape is
    // gated on build_state=failed OR source_type=instagram, so a healthy GBP build
    // must skip the registry entirely — never re-scrape on approval.
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_gbp_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'google_business', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-gbp@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead-gbp@example.com', 'email_lc' => 'lead-gbp@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    // Asserting `for()` is never called proves generate() can't have run either —
    // the registry is only ever touched inside the $needsScrape branch.
    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $mock->shouldReceive('for')->never();
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($signup->fresh()->status)->toBe('invited');
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead-gbp@example.com');
});

it('flips to failed and does not notify when the re-scrape throws (never invite on a failed refresh)', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'unclaimed', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_scrapefail_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-scrapefail@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $signup = EarlyAccessSignup::create([
        'email' => 'lead-scrapefail@example.com', 'email_lc' => 'lead-scrapefail@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    $this->mock(SourceGeneratorRegistry::class, function ($mock) {
        $gen = new class implements SiteSourceGenerator
        {
            public function normalizeRef(string $raw): string
            {
                return $raw;
            }

            public function dedupeKey(string $normalizedRef): string
            {
                return $normalizedRef;
            }

            public function handleSeed(string $normalizedRef, ?string $sourceName): string
            {
                return $normalizedRef;
            }

            public function generate(User $user, Site $site, string $sourceRef): void
            {
                throw SourceGenerationException::scrapeFailed('boom');
            }
        };
        $mock->shouldReceive('for')->andReturn($gen);
    });

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($build->fresh()->build_state)->toBe(PreAccountBuild::STATE_FAILED)
        ->and($build->fresh()->expires_at)->toBeNull()
        ->and($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

it('no-ops when the signup has no linked build (user_id null)', function () {
    Mail::fake();
    $signup = EarlyAccessSignup::create([
        'email' => 'unlinked@example.com', 'email_lc' => 'unlinked@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);

    // No linked build → source_type is unused (the job early-returns); pass a
    // valid literal to satisfy the required constructor arg.
    (new ApproveEarlyAccessBuildJob($signup->id, 'instagram'))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});

it('no-ops when the build is already claimed', function () {
    Mail::fake();
    $user = User::factory()->create(['status' => 'active', 'display_name' => 'Jane']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'ea_claimed_jane']);
    $build = PreAccountBuild::factory()->make([
        'source_type' => 'instagram', 'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'expires_at' => null, 'contact_email' => 'lead-claimed@example.com',
    ]);
    $build->build_state = PreAccountBuild::STATE_READY;
    $build->user()->associate($user);
    $build->save();
    $build->forceFill(['claimed_at' => now()])->save(); // SEC-4: claimed_at is not fillable

    $signup = EarlyAccessSignup::create([
        'email' => 'lead-claimed@example.com', 'email_lc' => 'lead-claimed@example.com', 'type' => 'partna',
        'status' => EarlyAccessSignup::STATUS_WAITLIST, 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    (new ApproveEarlyAccessBuildJob($signup->id, $build->source_type))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($build->fresh()->expires_at)->toBeNull()
        ->and($signup->fresh()->status)->toBe('waitlist');
    Mail::assertNothingQueued();
});
