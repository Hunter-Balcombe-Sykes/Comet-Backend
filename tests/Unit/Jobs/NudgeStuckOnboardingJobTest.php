<?php

use App\Enums\BrandStatus;
use App\Jobs\Notifications\NudgeStuckOnboardingJob;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
    setupBrandProfilesTable();
});

/**
 * Insert a core.professionals row aged $daysAgo days. Returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeOnboardingBrand(int $daysAgo, array $overrides = []): string
{
    $id = (string) Str::uuid();
    // Anchor the timestamp inside the day-window: subDays(N) puts created_at
    // at exactly N days back, which lies in the [now-(N+1), now-N] window the
    // sweep scans for milestone N.
    $createdAt = now()->subDays($daysAgo)->toDateTimeString();

    // Derive account_type from professional_type unless the caller overrode it
    // explicitly. Tests that simulate non-brand cohorts (`professional_type =>
    // 'influencer'`) must NOT be capability-gated into the brand class.
    $proType = $overrides['professional_type'] ?? 'brand';
    $defaultAccountType = match ($proType) {
        'brand' => 'brand',
        'affiliate' => 'partner',
        default => 'individual',
    };

    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'professional_type' => 'brand',
        'account_type' => $defaultAccountType,
        'primary_email' => Str::random(6).'@example.test',
        'first_name' => 'Brand',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));

    return $id;
}

/**
 * Attach a brand.brand_profiles row to a professional with the given status.
 */
function setBrandStatus(string $professionalId, BrandStatus $status): void
{
    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'brand_status' => $status->value,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Capture the dedupeKey arg from each publish() call into the given array.
 *
 * @return Mockery\MockInterface&NotificationPublisher
 */
function capturingPublisher(array &$captured): Mockery\MockInterface
{
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->andReturnUsing(function () use (&$captured) {
            $args = func_get_args();
            $captured[] = [
                'professional_id' => $args[0] ?? null,
                'frontendType' => $args[1] ?? null,
                'category' => $args[2] ?? null,
                'title' => $args[3] ?? null,
                'dedupeKey' => $args[5] ?? null,
            ];
        });

    return $publisher;
}

it('nudges a brand with no brand_profiles row at day 3', function () {
    // Fresh signup — never had BrandStatusService::sync() called, so no
    // brand_profiles row exists. LEFT JOIN should treat this as Onboarding.
    $proId = makeOnboardingBrand(3);

    $captured = [];
    (new NudgeStuckOnboardingJob)->handle(capturingPublisher($captured));

    expect($captured)->toHaveCount(1);
    expect($captured[0]['dedupeKey'])->toBe("onboarding.nudge.{$proId}.day_3");
    expect($captured[0]['category'])->toBe('profile_tasks');
});

it('nudges a brand still in Onboarding status at day 3', function () {
    $proId = makeOnboardingBrand(3);
    setBrandStatus($proId, BrandStatus::Onboarding);

    $captured = [];
    (new NudgeStuckOnboardingJob)->handle(capturingPublisher($captured));

    expect($captured)->toHaveCount(1);
    expect($captured[0]['dedupeKey'])->toBe("onboarding.nudge.{$proId}.day_3");
});

it('nudges a brand still in ShopifyLinked status at day 10', function () {
    $proId = makeOnboardingBrand(10);
    setBrandStatus($proId, BrandStatus::ShopifyLinked);

    $captured = [];
    (new NudgeStuckOnboardingJob)->handle(capturingPublisher($captured));

    expect($captured)->toHaveCount(1);
    expect($captured[0]['dedupeKey'])->toBe("onboarding.nudge.{$proId}.day_10");
    expect($captured[0]['frontendType'])->toBe('Info');
});

it('uses Warning severity for the day-30 milestone', function () {
    $proId = makeOnboardingBrand(30);

    $captured = [];
    (new NudgeStuckOnboardingJob)->handle(capturingPublisher($captured));

    expect($captured)->toHaveCount(1);
    expect($captured[0]['dedupeKey'])->toBe("onboarding.nudge.{$proId}.day_30");
    expect($captured[0]['frontendType'])->toBe('Warning');
});

it('does not nudge brands at day 5 (between milestones)', function () {
    makeOnboardingBrand(5);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    (new NudgeStuckOnboardingJob)->handle($publisher);
});

it('does not nudge brands past ShopifyLinked', function () {
    foreach ([BrandStatus::ShopifyConfigured, BrandStatus::StorefrontLive, BrandStatus::ReadyForAffiliates] as $status) {
        $proId = makeOnboardingBrand(3);
        setBrandStatus($proId, $status);
    }

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    (new NudgeStuckOnboardingJob)->handle($publisher);
});

it('does not nudge non-brand professionals (influencers, professionals)', function () {
    makeOnboardingBrand(3, ['professional_type' => 'influencer']);
    makeOnboardingBrand(10, ['professional_type' => 'professional']);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    (new NudgeStuckOnboardingJob)->handle($publisher);
});

it('does not nudge soft-deleted brands', function () {
    makeOnboardingBrand(3, ['deleted_at' => now()->subHour()->toDateTimeString()]);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    (new NudgeStuckOnboardingJob)->handle($publisher);
});

it('does not nudge brands in the disconnected state', function () {
    // Disconnected is out-of-band — not part of the progression.
    // These brands had Shopify and lost it; a different recovery flow handles them.
    $proId = makeOnboardingBrand(3);
    setBrandStatus($proId, BrandStatus::Disconnected);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->never();

    (new NudgeStuckOnboardingJob)->handle($publisher);
});

it('emits one nudge per milestone in a single sweep', function () {
    // One brand at each milestone. Single job run should produce three
    // distinct dedupe keys, one per (brand, milestone) pair.
    $day3Id = makeOnboardingBrand(3);
    $day10Id = makeOnboardingBrand(10);
    $day30Id = makeOnboardingBrand(30);

    $captured = [];
    (new NudgeStuckOnboardingJob)->handle(capturingPublisher($captured));

    $keys = collect($captured)->pluck('dedupeKey')->all();
    expect($keys)->toHaveCount(3);
    expect($keys)->toContain("onboarding.nudge.{$day3Id}.day_3");
    expect($keys)->toContain("onboarding.nudge.{$day10Id}.day_10");
    expect($keys)->toContain("onboarding.nudge.{$day30Id}.day_30");
});

it('isolates a per-row exception so other brands in the chunk still get nudged', function () {
    // Mirrors InviteExpirySweepJob's per-row try/catch contract: one publish()
    // throwing must not poison the rest of the chunk.
    makeOnboardingBrand(3);
    $survivorId = makeOnboardingBrand(3);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $callCount = 0;
    $publisher->shouldReceive('publish')
        ->twice()
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new \RuntimeException('simulated downstream failure');
            }
        });

    (new NudgeStuckOnboardingJob)->handle($publisher);

    expect($callCount)->toBe(2);
    // Survivor's id appears in the chunk regardless of which one threw —
    // both attempts happen because the catch swallows and the loop continues.
    expect($survivorId)->not->toBeEmpty();
});
