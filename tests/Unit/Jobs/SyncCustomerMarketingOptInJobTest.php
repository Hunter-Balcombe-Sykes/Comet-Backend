<?php

use App\Jobs\Notifications\SyncCustomerMarketingOptInJob;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\User\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupCustomersTable();
    setupEmailSubscriptionsTable();
});

/**
 * Save a marketing EmailSubscription via Eloquent so the saved() observer fires.
 * Raw DB::insert bypasses model events.
 */
function saveMarketingSubscription(string $userId, string $email, string $status = 'subscribed'): EmailSubscription
{
    $sub = new EmailSubscription;
    $sub->id = (string) Str::uuid();
    $sub->user_id = $userId;
    $sub->list_key = 'marketing';
    $sub->email = $email;
    $sub->email_lc = strtolower($email);
    $sub->status = $status;
    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
    $sub->save();

    return $sub;
}

it('CACHE-11: EmailSubscription save dispatches the sync job instead of looking up Customer synchronously', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    $sub = saveMarketingSubscription($proId, 'buyer@example.test', 'subscribed');

    // B3/P1-10: job payload must carry only UUIDs (no raw email or subscribed
    // bool). Email + status are derived in handle() from the persisted
    // EmailSubscription row so the Redis payload contains no PII.
    Queue::assertPushed(SyncCustomerMarketingOptInJob::class, function ($job) use ($proId, $sub) {
        return $job->userId === $proId
            && $job->subscriptionId === (string) $sub->id;
    });
});

it('CACHE-11: unsubscribe save dispatches the job carrying the subscription id only', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    $sub = saveMarketingSubscription($proId, 'buyer@example.test', 'unsubscribed');

    // The subscribed bool used to be a constructor prop. It's now derived from
    // EmailSubscription.status inside handle() — so we assert the dispatcher
    // wires the subscription id, not the bool.
    Queue::assertPushed(SyncCustomerMarketingOptInJob::class, function ($job) use ($sub) {
        return $job->subscriptionId === (string) $sub->id;
    });
});

it('CACHE-11: non-marketing list keys do not dispatch the sync job', function () {
    Queue::fake();

    $proId = (string) Str::uuid();
    $sub = new EmailSubscription;
    $sub->id = (string) Str::uuid();
    $sub->user_id = $proId;
    $sub->list_key = 'sidest_updates';
    $sub->email = 'staff@example.test';
    $sub->email_lc = 'staff@example.test';
    $sub->status = 'subscribed';
    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
    $sub->save();

    Queue::assertNotPushed(SyncCustomerMarketingOptInJob::class);
});

it('CACHE-11 job: updates Customer.marketing_opt_in_cached when the customer exists', function () {
    $proId = (string) Str::uuid();
    $sub = saveMarketingSubscription($proId, 'buyer@example.test', 'subscribed');
    DB::connection('pgsql')->table('site.customers')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'email' => 'buyer@example.test',
        'full_name' => 'Buyer',
        'marketing_opt_in_cached' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    (new SyncCustomerMarketingOptInJob($proId, (string) $sub->id))->handle();

    $customer = Customer::query()->where('user_id', $proId)->where('email', 'buyer@example.test')->first();
    expect($customer->marketing_opt_in_cached)->toBeTrue();

    // Flip the subscription to unsubscribed and re-run — cached column should flip too.
    $sub->status = 'unsubscribed';
    $sub->save();

    (new SyncCustomerMarketingOptInJob($proId, (string) $sub->id))->handle();
    $customer->refresh();
    expect($customer->marketing_opt_in_cached)->toBeFalse();
});

it('CACHE-11 job: is a no-op when no matching Customer exists', function () {
    $proId = (string) Str::uuid();
    $sub = saveMarketingSubscription($proId, 'ghost@example.test', 'subscribed');

    // Should neither throw nor insert a Customer row.
    (new SyncCustomerMarketingOptInJob($proId, (string) $sub->id))->handle();

    expect(DB::connection('pgsql')->table('site.customers')->count())->toBe(0);
});

// B3/P1-10: when the EmailSubscription row is gone at handle() time (GDPR erasure
// raced ahead of the queue), the job must no-op silently — no PII source to
// leak, nothing to update.
it('B3/P1-10 job: is a no-op when the EmailSubscription row no longer exists', function () {
    $proId = (string) Str::uuid();
    $missingSubscriptionId = (string) Str::uuid();

    // Should neither throw nor touch any Customer row.
    (new SyncCustomerMarketingOptInJob($proId, $missingSubscriptionId))->handle();

    expect(DB::connection('pgsql')->table('site.customers')->count())->toBe(0);
});

// §28.17 JOB-3 — failed() must call report() so Nightwatch sees exhaustion.
// B3/P1-11 — failed() log context must NOT contain the customer email; keep
// user_id + subscription_id for correlation.
it('JOB-3 + B3/P1-11: failed() reports exception and logs UUIDs only (no email)', function () {
    \Illuminate\Support\Facades\Log::spy();

    $exception = new \RuntimeException('boom');
    $reported = null;
    app()->bind(\Illuminate\Contracts\Debug\ExceptionHandler::class, function () use (&$reported) {
        return new class($reported) implements \Illuminate\Contracts\Debug\ExceptionHandler
        {
            public function __construct(public &$captured) {}

            public function report(\Throwable $e): void
            {
                $this->captured = $e;
            }

            public function shouldReport(\Throwable $e): bool
            {
                return true;
            }

            public function render($request, \Throwable $e): \Symfony\Component\HttpFoundation\Response
            {
                return new \Symfony\Component\HttpFoundation\Response;
            }

            public function renderForConsole($output, \Throwable $e): void {}
        };
    });

    (new SyncCustomerMarketingOptInJob('p1', 'sub-id-1'))->failed($exception);

    expect($reported)->toBe($exception);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'notifications.sync_customer_marketing_opt_in.failed'
                && $context['error'] === 'boom'
                && $context['user_id'] === 'p1'
                && $context['subscription_id'] === 'sub-id-1'
                && ! array_key_exists('email', $context)
                && ! array_key_exists('subscribed', $context);
        });
});
