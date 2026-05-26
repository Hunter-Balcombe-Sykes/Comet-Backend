<?php

use App\Models\Core\User\Customer;
use App\Services\User\SiteProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Lock in the two boolean-default corrections shipped in
// supabase/migrations/20260525104153_correct_boolean_defaults.sql:
//   1. New sites publish on creation (was: created unpublished).
//   2. New customers carry a NULL marketing_opt_in_cached, meaning
//      "look it up against email_subscriptions" — not "true / opted-in".
beforeEach(function () {
    setupSitesTable();
    setupUsersTable();
    setupCustomersTable();
    setupEmailSubscriptionsTable();

    // Shared setupSitesTable() omits theme_id (the standalone strip kept it
    // nullable but the helper hasn't caught up). Site::$fillable includes it,
    // so Eloquent inserts the column — patch the SQLite schema to match.
    try {
        DB::connection('pgsql')->statement('ALTER TABLE site.sites ADD COLUMN theme_id TEXT NULL');
    } catch (Throwable $e) {
        // Column already exists from a prior test run in the same process.
    }
})->group('boolean-defaults');

it('provisions new sites with is_published=true', function () {
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'newpro',
        'handle_lc' => 'newpro',
        'display_name' => 'New Pro',
        'primary_email' => 'newpro@example.test',
        'status' => 'active',
        'account_type' => 'individual',
    ]);

    $site = app(SiteProvisioningService::class)
        ->createSiteWithRetry($userId, 'newpro');

    expect($site->is_published)->toBeTrue()
        ->and($site->subdomain)->toBe('newpro');
});

it('treats a fresh customer marketing_opt_in_cached as un-synced (null)', function () {
    $proId = (string) Str::uuid();

    // Direct insert with no marketing_opt_in_cached column — mirrors what a
    // production INSERT will do once the column DEFAULT is dropped.
    $customerId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.customers')->insert([
        'id' => $customerId,
        'user_id' => $proId,
        'email' => 'cust@example.test',
        'full_name' => 'Cust',
        'source' => 'manual',
    ]);

    $customer = Customer::query()->findOrFail($customerId);

    expect($customer->marketing_opt_in_cached)->toBeNull();

    // Without a subscription row, the live lookup must return false. A `true`
    // column default would lie here — the contract is "null means look it up,
    // and the upstream truth is unsubscribed until proven otherwise".
    expect($customer->isMarketingOptedIn())->toBeFalse();

    // Once an EmailSubscription marks the customer subscribed, the live lookup
    // flips to true. This is the path SyncCustomerMarketingOptInJob normally
    // populates the cache from.
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'list_key' => 'marketing',
        'email' => 'cust@example.test',
        'email_lc' => 'cust@example.test',
        'status' => 'subscribed',
        'unsubscribe_token' => Str::random(32),
    ]);

    expect($customer->isMarketingOptedIn())->toBeTrue();
});
