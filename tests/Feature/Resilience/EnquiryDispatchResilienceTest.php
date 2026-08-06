<?php

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Gate 4b of the enquiry path. The controller commits the enquiry row and THEN
 * dispatches two jobs; with Redis dead the dispatch throws AFTER the commit, so
 * pre-2026-08-06 the visitor got a 500 on a lead that had actually been saved,
 * retried, and created a duplicate. Drill 03 finding 3.
 */
beforeEach(function () {
    // Canonical stand-ins for tables that HAVE a tests/Pest.php helper — a
    // bespoke CREATE TABLE for these is rejected by NoLocalCanonicalTableDdlTest.
    tenantHelpersEnsureTables();
    setupEnquiriesTable();
    setupBlocksTable();
    setupCustomersTable();

    // analytics.lead_submissions has NO canonical helper: six existing test
    // files each declare their own. Follow that pattern rather than adding a
    // Pest.php helper, which would turn all six into local-canonical
    // violations. Do NOT reference the copy in DeadCacheStoreTest — Pest's
    // file-local functions are not visible across test files.
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
});

/**
 * Published site with an ACTIVE contact block in the `sections` group. Mirrors
 * seedPublishedContactSite() in PublicEnquirySubmissionTest.php — without an
 * active contact block the controller returns 422 before reaching the dispatch,
 * and every assertion below would pass vacuously.
 */
function seedDispatchContactSite(string $subdomain): void
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => $subdomain,
        'handle_lc' => strtolower($subdomain),
        'display_name' => 'Dispatch Pro',
        'first_name' => 'Dispatch Pro',
        'primary_email' => 'dispatch@example.test',
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
        'settings' => json_encode(['notification_email' => 'pro@example.test']),
    ]);
}

it('returns 200 and commits the enquiry when the queue is unreachable', function () {
    seedDispatchContactSite('dispatch-dead');

    Queue::shouldReceive('connection')->andThrow(
        new RuntimeException('read error on connection to 127.0.0.1:6379')
    );

    $this->withHeader('Origin', 'https://dispatch-dead.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'name' => 'Real Visitor',
            'email' => 'visitor@example.test',
            'subject' => config('partna.contact_subject_defaults')[0],
            'message' => 'Please call me back about a booking.',
            'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $enquiry = DB::connection('pgsql')->table('site.enquiries')->first();

    expect($enquiry)->not->toBeNull()
        ->and($enquiry->email)->toBe('visitor@example.test')
        // The marker is what the reconciler drains. Without it the lead is
        // captured but nobody is ever told, silently and permanently.
        ->and($enquiry->notifications_pending_since)->not->toBeNull();
});

it('emits a breadcrumb when the dispatch fails', function () {
    seedDispatchContactSite('dispatch-crumb');

    Queue::shouldReceive('connection')->andThrow(
        new RuntimeException('read error on connection to 127.0.0.1:6379')
    );

    Log::spy();

    $this->withHeader('Origin', 'https://dispatch-crumb.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'name' => 'Real Visitor',
            'email' => 'visitor@example.test',
            'subject' => config('partna.contact_subject_defaults')[0],
            'message' => 'Please call me back about a booking.',
            'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
        ]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'enquiry.notify.dispatch_failed')
        ->atLeast()->once();
});

it('leaves the marker null on a healthy dispatch', function () {
    seedDispatchContactSite('dispatch-healthy');

    Queue::fake();

    $this->withHeader('Origin', 'https://dispatch-healthy.'.config('partna.public_domain'))
        ->postJson('/api/public/enquiry', [
            'name' => 'Real Visitor',
            'email' => 'visitor@example.test',
            'subject' => config('partna.contact_subject_defaults')[0],
            'message' => 'Please call me back about a booking.',
            'form_started_at_ms' => (int) floor(microtime(true) * 1000) - 5000,
        ])
        ->assertOk();

    Queue::assertPushed(DispatchEnquiryNotificationsJob::class);
    Queue::assertPushed(SendEnquiryConfirmationJob::class);

    // The partial index is only cheap because this is NULL in steady state.
    expect(DB::connection('pgsql')->table('site.enquiries')->first()->notifications_pending_since)
        ->toBeNull();
});
