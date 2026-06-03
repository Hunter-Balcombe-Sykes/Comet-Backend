<?php

// TEST-3: Rate-limit early-return path coverage for both confirmation jobs.
//
// Both SendEnquiryConfirmationJob and SendSubscriptionConfirmationJob call
// withinRateLimit($recipient) before sending mail. When the rate limiter
// reports too many attempts the job returns early — no mail sent, no
// confirmation_sent_at stamp written. A misconfigured limit (e.g. 0) would
// silently suppress all confirmations forever; these tests keep that path
// honest.
//
// Mechanism: the jobs call RateLimiter::tooManyAttempts($key, $limit). We
// exhaust the limit by calling RateLimiter::hit() $limit times BEFORE
// dispatching the job so tooManyAttempts() returns true on the job's first
// real check. This drives the real rate-limiter code path rather than
// mocking it, so the test stays truthful about what happens in production.
//
// Schema note: both jobs call DB::transaction(fn() => lockForUpdate()->find())
// before the rate-limit check, so all needed tables must be seeded first.

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Schema bootstrap — mirrors SendEnquiryConfirmationJobBrandTest and
// SendSubscriptionConfirmationJobBrandTest so table layout is consistent.
// ---------------------------------------------------------------------------
beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('visitor_confirmation:'.hash('sha256', 'ratelimit@example.com'));
    setupUsersTable();
    setupContactInboxSchema();
    setupSitesTable();
    setupDesignKitsTable();
    setupMediaTables();
    setupSubdomainAliasesTable();
    setupEmailSubscriptionsTable();
    setupBlocksTable();
});

// ---------------------------------------------------------------------------
// Helper: exhaust the rate-limit bucket for the given email address so the
// next withinRateLimit() call returns false. Uses the exact same key formula
// that both job classes use.
// ---------------------------------------------------------------------------
function exhaustRateLimitFor(string $email): void
{
    $key = 'visitor_confirmation:'.hash('sha256', strtolower(trim($email)));
    $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);

    // Hit $limit times so tooManyAttempts() returns true on the next attempt.
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }
}

// ---------------------------------------------------------------------------
// ENQUIRY JOB
// ---------------------------------------------------------------------------

it('does not send mail when the enquiry visitor confirmation rate limit is exceeded', function () {
    Mail::fake();
    Log::spy();

    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane-rl', 'handle_lc' => 'jane-rl']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    $enquiryId = seedInboxEnquiry($user->id, $site->id, [
        'email' => 'ratelimit@example.com',
        'name' => 'Visitor',
        'subject' => 'Test',
        'confirmation_sent_at' => null,
    ]);

    // Pre-exhaust the rate-limit bucket so the job hits the early-return path.
    exhaustRateLimitFor('ratelimit@example.com');

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    // (a) No email must have been sent.
    Mail::assertNothingSent();

    // (b) The job must have logged the rate-limit warning with the exact message
    //     emitted by SendEnquiryConfirmationJob::withinRateLimit().
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($msg, $ctx = []) => str_contains(
            (string) $msg,
            'SendEnquiryConfirmationJob: visitor confirmation rate limit exceeded'
        )
    );

    // (c) confirmation_sent_at IS stamped: the lockForUpdate transaction (TXN-1
    //     fix) commits the check-and-set BEFORE the rate-limit guard is reached.
    //     Stamping first is intentional — it blocks any retry from re-sending
    //     once the rate window passes, so the rate-limited send is dropped once,
    //     not deferred.
    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('does not send mail when enquiry rate limit is exceeded and visitor has no contact block', function () {
    Mail::fake();
    Log::spy();

    $user = User::factory()->create(['display_name' => 'No Block Pro', 'handle' => 'noblockrl', 'handle_lc' => 'noblockrl']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    // Seed an enquiry with no corresponding contact block. The block check runs
    // before the rate-limit check — with no block the code falls through to
    // withinRateLimit(). This covers the "null block → still rate-limited" path.
    $enquiryId = seedInboxEnquiry($user->id, $site->id, [
        'email' => 'ratelimit@example.com',
        'name' => 'Visitor',
        'subject' => 'No Block',
        'confirmation_sent_at' => null,
    ]);

    exhaustRateLimitFor('ratelimit@example.com');

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($msg) => str_contains(
            (string) $msg,
            'SendEnquiryConfirmationJob: visitor confirmation rate limit exceeded'
        )
    );
});

// ---------------------------------------------------------------------------
// SUBSCRIPTION JOB
// ---------------------------------------------------------------------------

it('does not send mail when the subscription visitor confirmation rate limit is exceeded', function () {
    Mail::fake();
    Log::spy();

    $user = User::factory()->create(['display_name' => 'Sub Pro', 'handle' => 'subrl', 'handle_lc' => 'subrl']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $subId,
        'user_id' => $user->id,
        'list_key' => 'marketing',
        'email' => 'ratelimit@example.com',
        'email_lc' => 'ratelimit@example.com',
        'full_name' => 'Rate Visitor',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.Str::random(12),
        'confirmation_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Pre-exhaust the shared bucket for this email address.
    exhaustRateLimitFor('ratelimit@example.com');

    (new SendSubscriptionConfirmationJob($subId))->handle();

    // (a) No email sent.
    Mail::assertNothingSent();

    // (b) Warning logged with the exact message from SendSubscriptionConfirmationJob::withinRateLimit().
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($msg, $ctx = []) => str_contains(
            (string) $msg,
            'SendSubscriptionConfirmationJob: visitor confirmation rate limit exceeded'
        )
    );

    // (c) Like the enquiry job, confirmation_sent_at is stamped under the
    //     lockForUpdate transaction which runs BEFORE the rate-limit guard.
    //     The stamp is committed; the rate-limit blocks the mail. No re-send
    //     can occur — the idempotency check bails any retry attempt.
    $row = DB::connection('pgsql')
        ->table('notifications.email_subscriptions')
        ->where('id', $subId)
        ->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('does not send mail when subscription rate limit is exceeded even with no newsletter block', function () {
    Mail::fake();
    Log::spy();

    $user = User::factory()->create(['display_name' => 'No Block Sub', 'handle' => 'noblocksubrl', 'handle_lc' => 'noblocksubrl']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);

    // No newsletter block seeded — withinRateLimit() is the only remaining gate.
    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $subId,
        'user_id' => $user->id,
        'list_key' => 'marketing',
        'email' => 'ratelimit@example.com',
        'email_lc' => 'ratelimit@example.com',
        'full_name' => 'No Block Visitor',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => 'tok-'.Str::random(12),
        'confirmation_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    exhaustRateLimitFor('ratelimit@example.com');

    (new SendSubscriptionConfirmationJob($subId))->handle();

    Mail::assertNothingSent();

    Log::shouldHaveReceived('warning')->withArgs(
        fn ($msg) => str_contains(
            (string) $msg,
            'SendSubscriptionConfirmationJob: visitor confirmation rate limit exceeded'
        )
    );
});
