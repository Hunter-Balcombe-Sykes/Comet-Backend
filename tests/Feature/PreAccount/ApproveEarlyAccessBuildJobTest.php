<?php

use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimNotifier;
use App\Services\PreAccount\Generators\SiteSourceGenerator;
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

    (new ApproveEarlyAccessBuildJob($signup->id))->handle(app(SourceGeneratorRegistry::class), app(ClaimNotifier::class));

    expect($build->fresh()->expires_at)->not->toBeNull()
        ->and($signup->fresh()->status)->toBe('invited');
    Mail::assertQueued(ClaimInviteMail::class, fn ($m) => $m->recipientEmail === 'lead@example.com');
});
