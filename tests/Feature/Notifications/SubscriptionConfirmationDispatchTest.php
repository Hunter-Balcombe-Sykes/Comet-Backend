<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Notifications\EmailSubscription;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEmailSubscriptionsTable();
    setupCustomersTable();
    setupSegmentsTables();            // feature-availability segment rules
    setupFeatureAvailabilityTable();  // else the gate fails OPEN in tests
});

function seedPublishedSubscribeSite(string $subdomain = 'subpro'): string
{
    $userId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => 'Sub Pro',
        'first_name' => 'Sub Pro',
        'primary_email' => 'subpro@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    return $userId;
}

it('dispatches the confirmation on a brand-new subscribe', function () {
    seedPublishedSubscribeSite();
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'new@example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});

it('does NOT dispatch on a redundant re-submit of an already-subscribed address', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'already@example.com',
        'email_lc' => 'already@example.com',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-already',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'already@example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertNotDispatched(SendSubscriptionConfirmationJob::class);
});

it('dispatches again when a previously-unsubscribed address re-subscribes', function () {
    $userId = seedPublishedSubscribeSite();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'back@example.com',
        'email_lc' => 'back@example.com',
        'status' => 'unsubscribed',
        'unsubscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-back',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'back@example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    Bus::assertDispatched(SendSubscriptionConfirmationJob::class);
});

// S4 Tier 2b: user_id (site-owner attribution, NULLABLE — a silent drop would
// orphan the row into a global-list subscription) and email_lc (backs the
// per-list uniqueness index) were removed from EmailSubscription's $fillable.
// PublicEmailSubscriptionController::subscribe() now sets both via direct
// property assignment; assert they actually persist on a brand-new row.
it('persists user_id and email_lc on a brand-new subscribe', function () {
    $userId = seedPublishedSubscribeSite();
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'Fresh@Example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    $sub = EmailSubscription::query()->where('email', 'fresh@example.com')->firstOrFail()->fresh();

    expect($sub->user_id)->toBe($userId)
        ->and($sub->email_lc)->toBe('fresh@example.com');
});

// PGR-19: was stored fully raw and uncapped — now routed through
// AnalyticsEventSanitizer::userAgent() like PublicCustomerLeadController.
it('stores a coarse User-Agent token, not the raw string, on a brand-new subscribe', function () {
    seedPublishedSubscribeSite();
    Bus::fake();

    $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/141.0.7390.54 Safari/537.36';

    $this->withHeader('User-Agent', $chromeUa)
        ->postJson('/api/public/subscribe', ['email' => 'ua-check@example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
            'X-Site-Subdomain' => 'subpro',
        ])->assertOk();

    $sub = EmailSubscription::query()->where('email', 'ua-check@example.com')->firstOrFail();

    expect($sub->consent_user_agent)->toBe('Chrome/141');
});

// PGR-16: the lookup used whereRaw('lower(email) = ?', ...) instead of the
// indexed, already-lower-cased email_lc column. Seed a row whose stored
// `email` casing deliberately diverges from `email_lc` (simulating a legacy
// row from before a correction) — a reader that computes lower(email)
// instead of reading email_lc would compute a DIFFERENT string than the
// submitted address, miss the existing row, and insert a duplicate.
it('finds the existing subscription via email_lc even when the stored email casing has drifted from it', function () {
    $userId = seedPublishedSubscribeSite();
    $existingId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $existingId,
        'user_id' => $userId,
        'list_key' => 'marketing',
        'email' => 'Legacy-Casing@Example.com',
        'email_lc' => 'reader2@example.com',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-legacy',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    Bus::fake();

    $this->postJson('/api/public/subscribe', ['email' => 'Reader2@Example.com', 'form_started_at_ms' => time() * 1000 - 5000], [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk();

    // Still exactly one row for this user+list — the submit updated the
    // email_lc-matched row in place rather than inserting a duplicate (which
    // whereRaw('lower(email)...') would have done, since lower() of the
    // drifted stored email no longer equals the submitted address).
    expect(EmailSubscription::query()->where('user_id', $userId)->where('list_key', 'marketing')->count())->toBe(1);

    $sub = EmailSubscription::query()->find($existingId)->fresh();
    expect($sub->email)->toBe('reader2@example.com')
        ->and($sub->email_lc)->toBe('reader2@example.com');
});

function validSubscribePayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'reader@example.com',
        'full_name' => 'Reader Person',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('422s the subscribe submit when feature.email_signup is globally disabled', function () {
    seedPublishedSubscribeSite();
    Bus::fake(); // confirmation job runs inline on the sync queue otherwise

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.email_signup',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/subscribe', validSubscribePayload(), [
        'X-Site-Subdomain' => 'subpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No subscription row written — the gate fired before persistence.
    expect(EmailSubscription::query()->count())->toBe(0);
});

it('allows the subscribe submit when no availability rule exists', function () {
    seedPublishedSubscribeSite();
    Bus::fake(); // confirmation job runs inline on the sync queue otherwise

    $this->postJson('/api/public/subscribe', validSubscribePayload(), [
        'X-Site-Subdomain' => 'subpro',
    ])->assertOk()->assertJson(['subscribed' => true]);

    expect(EmailSubscription::query()->count())->toBe(1);
});
