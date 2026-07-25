<?php

use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
    // Customer::redact() unconditionally UPDATEs analytics.lead_submissions
    // (models-data/PRIV-1 cascade) — every test in this file calls redact(),
    // so the table must exist regardless of whether a lead was seeded.
    setupLeadSubmissionsTable();
});

/**
 * analytics.lead_submissions — built from the REAL DDL (baseline
 * supabase/migrations-archive/20260526000000_baseline_standalone_user.sql:1189-1205),
 * not copied from DataExportTestCase.php's stub, which has a spurious
 * created_at column production does not have. NOT NULL matches prod:
 * occurred_at and outcome only; everything else — including customer_id — is
 * nullable (ON DELETE SET NULL FKs).
 */
function setupLeadSubmissionsTable(): void
{
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NOT NULL,
        subdomain TEXT NULL,
        site_id TEXT NULL,
        user_id TEXT NULL,
        customer_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        outcome TEXT NOT NULL,
        form_started_at_ms INTEGER NULL
    )');
}

function seedLeadSubmission(?string $customerId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('analytics.lead_submissions')->insert(array_merge([
        'id' => $id,
        'occurred_at' => now()->toDateTimeString(),
        'customer_id' => $customerId,
        'ip_hash' => 'hash-'.substr($id, 0, 8),
        'user_agent' => 'TestAgent/1.0',
        'referrer' => 'https://example.com/ref',
        'outcome' => 'submitted',
    ], $overrides));

    return $id;
}

it('redact() nulls customer PII and cascades to linked enquiries', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, [
        'email' => 'foo@example.com',
        'full_name' => 'Foo Bar',
    ]);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId,
        'name' => 'Foo Bar',
        'email' => 'foo@example.com',
        'subject' => "Hi, it's Foo Bar",
    ]);

    $customer = Customer::find($customerId);
    $customer->redact();

    $fresh = Customer::find($customerId);
    expect($fresh->email)->toBeNull();
    expect($fresh->full_name)->toBeNull();
    expect($fresh->redacted_at)->not->toBeNull();

    $freshEnquiry = Enquiry::find($enquiryId);
    expect($freshEnquiry->name)->toBeNull();
    expect($freshEnquiry->redacted_at)->not->toBeNull();
    // models-data/PRIV-4 cascade: Customer::redact()'s bulk Enquiry UPDATE
    // must apply the same '[redacted]' subject treatment as Enquiry::redact()
    // itself, or the two paths diverge (site.enquiries.subject is NOT NULL
    // in Postgres, so nulling it here would 23502 in production).
    expect($freshEnquiry->subject)->toBe('[redacted]');
});

it('redact() cascades to linked lead submissions but leaves other customers\' rows untouched (models-data/PRIV-1)', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'foo@example.com']);
    $otherCustomerId = seedInboxCustomer($user->id, ['email' => 'other@example.com']);

    $leadId = seedLeadSubmission($customerId);
    $otherLeadId = seedLeadSubmission($otherCustomerId);

    $customer = Customer::find($customerId);
    $customer->redact();

    $fresh = LeadSubmission::find($leadId);
    expect($fresh->ip_hash)->toBeNull();
    expect($fresh->user_agent)->toBeNull();
    expect($fresh->referrer)->toBeNull();

    // Guards a missing/loose `where('customer_id', ...)` scope — a different
    // customer's lead-capture telemetry must survive untouched.
    $otherFresh = LeadSubmission::find($otherLeadId);
    expect($otherFresh->ip_hash)->not->toBeNull();
    expect($otherFresh->user_agent)->not->toBeNull();
    expect($otherFresh->referrer)->not->toBeNull();
});
