<?php

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\User\Customer;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);

    setupUsersTable();
    setupSitesTable();
    setupCustomersTable();
    setupEmailSubscriptionsTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    // analytics.lead_submissions has no global helper — the controller's logLead()
    // writes here on the allowed path. Same DDL as the enquiry suite.
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY, occurred_at TEXT NULL, subdomain TEXT NULL, site_id TEXT NULL,
        user_id TEXT NULL, customer_id TEXT NULL, ip_hash TEXT NULL, user_agent TEXT NULL,
        referrer TEXT NULL, outcome TEXT NULL, form_started_at_ms INTEGER NULL
    )');
});

function seedPublishedLeadSite(string $subdomain = 'leadpro'): string
{
    $userId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId, 'handle' => $subdomain, 'handle_lc' => $subdomain,
        'display_name' => 'Lead Pro', 'primary_email' => $subdomain.'@example.com', 'status' => 'active',
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $userId, 'subdomain' => $subdomain, 'is_published' => 1,
    ]);

    return $userId;
}

function validLeadPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Casey Lead',
        'email' => 'casey@example.com',
        'phone' => '+44 7700 900111',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('422s the customer-lead submit when feature.customer_leads is globally disabled', function () {
    seedPublishedLeadSite();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.customer_leads',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'leadpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No customer created — the gate fired before persistence.
    expect(Customer::query()->count())->toBe(0);
});

it('allows the customer-lead submit when no availability rule exists', function () {
    seedPublishedLeadSite();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'leadpro',
    ])->assertStatus(201)->assertJson(['ok' => true]);

    expect(Customer::query()->count())->toBe(1);
});

it('422s a customer-lead submitter whose owner is in a disabled segment', function () {
    $ownerId = seedPublishedLeadSite('segleadpro');

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $ownerId]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.customer_leads',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
        'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/customers', validLeadPayload(), [
        'X-Site-Subdomain' => 'segleadpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    expect(Customer::query()->count())->toBe(0);
});
