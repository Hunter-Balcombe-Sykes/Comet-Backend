<?php

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\Enquiry;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Notifications\EnquirySpamBlocklist;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);
    Cache::flush();

    setupContactSubmissionSchema();
});

function setupContactSubmissionSchema(): void
{
    // Core + site + analytics tables required for the full enquiry submission
    // flow: site resolution, block lookup, customer upsert, enquiry persistence,
    // and lead-submission analytics logging.
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupSegmentsTables();            // core.user_segments (+ members) — feature-availability segment rules
    setupFeatureAvailabilityTable();  // core.feature_availability — else the gate fails OPEN in tests

    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        site_id TEXT NOT NULL,
        customer_id TEXT NULL,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NULL,
        subject TEXT NOT NULL,
        message TEXT NOT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        read_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NULL,
        full_name TEXT NULL,
        source TEXT NULL,
        notes TEXT NULL,
        external_id TEXT NULL,
        marketing_opt_in_cached INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NULL,
        subdomain TEXT NULL,
        site_id TEXT NULL,
        user_id TEXT NULL,
        customer_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        outcome TEXT NULL,
        form_started_at_ms INTEGER NULL
    )');
}

function seedPublishedContactSite(string $subdomain = 'testpro'): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $subdomain,
        'handle_lc' => strtolower($subdomain),
        'display_name' => 'Test Pro',
        'first_name' => 'Test Pro',
        'primary_email' => 'test@example.com',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => $subdomain,
        'is_published' => 1,
    ]);

    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode([
            'notification_email' => 'hello@mybrand.com',
            'subject_options' => ['Wholesale'],
        ]),
    ]);

    return [$proId, $siteId];
}

function validEnquiryPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sarah Jones',
        'email' => 'sarah@example.com',
        'phone' => '+44 7700 900000',
        'subject' => 'Wholesale',
        'message' => 'Hi, I would love to stock your products in my shop.',
        'website' => '',
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('accepts a valid submission and saves a site.enquiries row', function () {
    [$proId, $siteId] = seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk()->assertJson(['ok' => true]);

    $row = DB::connection('pgsql')->table('site.enquiries')->first();
    expect($row)->not->toBeNull();
    expect($row->name)->toBe('Sarah Jones');
    expect($row->email)->toBe('sarah@example.com');
    expect($row->subject)->toBe('Wholesale');
    expect($row->user_id)->toBe($proId);
    expect($row->site_id)->toBe($siteId);
});

// user_id/site_id/customer_id are not fillable on Enquiry (tenancy FKs) —
// the controller sets them via ->associate(). Assert through the Eloquent
// model too (not just the raw row above) so a regression back to a bare
// create() call — which would silently drop these on the model instance —
// fails here even if the DB row happened to look right.
it('persists user_id/site_id/customer_id on the Enquiry model instance via associate()', function () {
    [$proId, $siteId] = seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $enquiry = Enquiry::query()->latest()->firstOrFail()->fresh();
    expect($enquiry->user_id)->toBe($proId);
    expect($enquiry->site_id)->toBe($siteId);
    expect($enquiry->customer_id)->not->toBeNull();
});

it('upserts submitter as a Customer with source=enquiry', function () {
    [$proId] = seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $customer = DB::connection('pgsql')->table('site.customers')->first();
    expect($customer)->not->toBeNull();
    expect($customer->email)->toBe('sarah@example.com');
    expect($customer->source)->toBe('enquiry');
    expect($customer->user_id)->toBe($proId);
});

it('does NOT dispatch SendEnquiryNotificationJob directly from the controller (now owned by EmailEnquiryNotificationAdapter)', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    // The controller dispatches DispatchEnquiryNotificationsJob (queued), not SendEnquiryNotificationJob directly.
    // Rate-limit logic and the actual email send are owned by EmailEnquiryNotificationAdapter.
    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);
    Bus::assertDispatched(DispatchEnquiryNotificationsJob::class);
    // Enquiry row is still saved.
    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(1);
});

it('rejects a subject not in the merged options list', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['subject' => 'NotAnOption']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('accepts a platform-default subject even if the affiliate never listed it', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['subject' => 'General enquiry']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();
});

it('rejects a message shorter than 10 chars', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['message' => 'too short']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('honeypot filled returns 200, saves nothing, and logs outcome=honeypot', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(['website' => 'http://spam.com']), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(0);
    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('honeypot');
});

it('too-fast submission is rejected with outcome=too_fast', function () {
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload([
        'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 100,
    ]), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);

    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(0);

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('too_fast');
});

it('rejects submission to a site without an active contact block', function () {
    seedPublishedContactSite();
    DB::connection('pgsql')->table('site.blocks')->where('block_type', 'contact')->update(['is_active' => 0]);

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422);
});

it('strips HTML tags from name and message', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload([
        'name' => 'Sarah <b>Jones</b>',
        'message' => '<em>Please</em> call me about wholesale.',
    ]), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $row = DB::connection('pgsql')->table('site.enquiries')->first();
    expect($row->name)->toBe('Sarah Jones');
    expect($row->message)->toBe('Please call me about wholesale.');
});

it('logs outcome=created on success', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    $lead = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($lead?->outcome)->toBe('created');
});

it('persists all enquiries regardless of rate limit and queues DispatchEnquiryNotificationsJob per submission', function () {
    seedPublishedContactSite();
    config(['partna.throttle.enquiry_notification_per_hour' => 2]);
    Bus::fake();

    // Rate-limit enforcement lives in EmailEnquiryNotificationAdapter (inside the queued job).
    // The controller always saves and always dispatches DispatchEnquiryNotificationsJob.
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
        $this->postJson('/api/public/enquiry', validEnquiryPayload(['email' => $email]), [
            'X-Site-Subdomain' => 'testpro',
        ])->assertOk();
    }

    // SendEnquiryNotificationJob is never dispatched directly from the controller.
    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);
    // One DispatchEnquiryNotificationsJob queued per submission.
    Bus::assertDispatchedTimes(DispatchEnquiryNotificationsJob::class, 3);
    expect(DB::connection('pgsql')->table('site.enquiries')->count())->toBe(3);
});

it('links the created Enquiry to the upserted Customer via customer_id', function () {
    seedPublishedContactSite();
    Bus::fake();

    $response = $this->postJson('/api/public/enquiry', validEnquiryPayload(['email' => 'alice@example.com']), [
        'X-Site-Subdomain' => 'testpro',
    ]);
    $response->assertOk();

    $enquiry = Enquiry::latest()->first();
    expect($enquiry->customer_id)->not->toBeNull();
    expect($enquiry->customer->email)->toBe('alice@example.com');
});

it('queues DispatchEnquiryNotificationsJob after enquiry persists', function () {
    Bus::fake();
    seedPublishedContactSite();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    Bus::assertDispatched(DispatchEnquiryNotificationsJob::class);
});

it('returns silent 200 + does NOT persist when sender email is in spam blocklist', function () {
    Redis::connection()->flushdb();

    [$proId] = seedPublishedContactSite();

    app(EnquirySpamBlocklist::class)
        ->add((string) $proId, 'spammer@example.com');

    $before = Enquiry::count();

    $response = $this->postJson('/api/public/enquiry', validEnquiryPayload(['email' => 'spammer@example.com']), [
        'X-Site-Subdomain' => 'testpro',
    ]);

    $response->assertOk();
    expect(Enquiry::count())->toBe($before);
});

it('response shape is {ok: true} only — no enquiry_id leaked', function () {
    Bus::fake();
    seedPublishedContactSite();

    $response = $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ]);
    $response->assertOk();

    expect(array_keys($response->json()))->toBe(['ok']);
});

it('dispatches SendEnquiryConfirmationJob to confirm receipt to the visitor', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk();

    Bus::assertDispatched(SendEnquiryConfirmationJob::class);
});

it('422s the enquiry submit when feature.enquiries is globally disabled', function () {
    seedPublishedContactSite();
    Bus::fake();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    // No row written, no notification dispatched — the gate fired before persistence.
    expect(Enquiry::query()->count())->toBe(0);
    Bus::assertNotDispatched(DispatchEnquiryNotificationsJob::class);
    Bus::assertNotDispatched(SendEnquiryConfirmationJob::class);
});

it('allows the enquiry submit when no availability rule exists', function () {
    seedPublishedContactSite();
    Bus::fake();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), [
        'X-Site-Subdomain' => 'testpro',
    ])->assertOk()->assertJson(['ok' => true]);

    expect(Enquiry::query()->count())->toBe(1);
});

it('422s a submitter whose owner is in a disabled segment, but allows one who is not', function () {
    [$proId] = seedPublishedContactSite('segpro');       // owner IN the segment
    seedPublishedContactSite('freepro');                 // owner NOT in the segment
    Bus::fake();

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $proId]);

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
        'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'segpro'])
        ->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'freepro'])
        ->assertOk();
});

it('gate precedence: disabled feature 422s even with a live contact block', function () {
    // seedPublishedContactSite() creates a live `contact` section block, so this
    // proves the feature gate fires BEFORE the existing contact-block check.
    seedPublishedContactSite();
    Bus::fake();

    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'feature.enquiries',
        'mode' => FeatureAvailabilityRule::MODE_DISABLED,
    ]);
    FeatureAvailability::flush();

    $this->postJson('/api/public/enquiry', validEnquiryPayload(), ['X-Site-Subdomain' => 'testpro'])
        ->assertStatus(422)->assertJson(['error' => 'FEATURE_UNAVAILABLE']);
});
