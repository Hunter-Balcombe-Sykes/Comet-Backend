<?php

use App\Models\Commerce\CommissionExportAudit;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupCommissionPayoutsTable();
    setupCommissionExportAuditTable();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create and persist a minimal Professional and wire up the actingAsProfessional stub.
 * Returns the Professional; the HTTP client is now authenticated as that pro.
 *
 * @param  array<string, mixed>  $overrides
 */
function cet_actingAsBrand(array $overrides = []): Professional
{
    $handle = 'cetbrand'.Str::random(6);
    $pro = Professional::create(array_merge([
        'id' => (string) Str::uuid(),
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'CET Brand',
        'primary_email' => $handle.'@example.com',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active', // CAPE-1: gate requires active Connect
    ], $overrides));

    actingAsProfessional($pro);

    return $pro;
}

/**
 * Create a second professional (for cross-tenant tests) without authenticating as them.
 *
 * @param  array<string, mixed>  $overrides
 */
function cet_makeProfessional(array $overrides = []): Professional
{
    $handle = 'cetother'.Str::random(6);

    return Professional::create(array_merge([
        'id' => (string) Str::uuid(),
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => 'CET Other',
        'primary_email' => $handle.'@example.com',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active',
    ], $overrides));
}

/**
 * Insert $count payout rows for $professionalId in the given role column.
 */
function cet_seedPayouts(string $professionalId, int $count, string $role = 'brand'): void
{
    $column = $role === 'brand' ? 'brand_professional_id' : 'affiliate_professional_id';
    $now = now()->toDateTimeString();

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            $column => $professionalId,
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $batch) {
        DB::connection('pgsql')->table('commerce.commission_payouts')->insert($batch);
    }
}

/**
 * Insert an audit row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function cet_makeAudit(Professional $pro, array $overrides = []): CommissionExportAudit
{
    $now = now()->toDateTimeString();
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('commerce.commission_export_audit')->insert(array_merge([
        'id' => $id,
        'professional_id' => $pro->id,
        'role' => 'brand',
        'format' => 'csv',
        'filters' => '{}',
        'status' => CommissionExportAudit::STATUS_QUEUED,
        'recipient_email' => $pro->primary_email,
        'payouts_total' => 0,
        'payouts_processed' => 0,
        'chunk_size' => 500,
        'chunks_total' => 0,
        'chunks_completed' => 0,
        'next_chunk_index' => 0,
        'created_at' => $now,
        'expires_at' => now()->addDays(3)->toDateTimeString(),
    ], $overrides));

    return CommissionExportAudit::findOrFail($id);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('POST returns 202 with the expected envelope and Location header', function () {
    Bus::fake();

    $pro = cet_actingAsBrand();
    cet_seedPayouts($pro->id, 3, 'brand');

    $response = test()->postJson('/api/stripe/exports/transactions', [
        'format' => 'csv',
        'date_from' => '2026-01-01',
        'date_to' => '2026-05-19',
    ]);

    $response->assertStatus(202)
        ->assertJsonStructure(['export_id', 'status', 'payouts_total', 'chunk_size', 'recipient_email', 'message'])
        ->assertJson(['status' => 'queued', 'payouts_total' => 3]);
    $response->assertHeader('Location');
});

it('POST returns 422 when professional has no email', function () {
    cet_actingAsBrand(['primary_email' => null, 'public_contact_email' => null]);

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'csv'])
        ->assertStatus(422);
});

it('POST returns 409 when an in-flight export already exists', function () {
    Bus::fake();

    $pro = cet_actingAsBrand();
    cet_seedPayouts($pro->id, 1, 'brand');

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'csv'])
        ->assertStatus(202);

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'csv'])
        ->assertStatus(409)
        ->assertJsonStructure(['message', 'export_id', 'status']);
});

it('POST returns 422 on an invalid format value', function () {
    cet_actingAsBrand();

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'pdf'])
        ->assertStatus(422);
});

it('GET show returns the audit row for the owning professional', function () {
    $pro = cet_actingAsBrand();
    $audit = cet_makeAudit($pro, ['status' => CommissionExportAudit::STATUS_PROCESSING]);

    // CommissionExportAuditResource wraps the payload under a 'data' key
    test()->getJson("/api/stripe/exports/commission/{$audit->id}")
        ->assertOk()
        ->assertJson(['data' => ['id' => $audit->id, 'status' => CommissionExportAudit::STATUS_PROCESSING]]);
});

it('GET show returns 404 for a cross-tenant audit row', function () {
    cet_actingAsBrand();
    $other = cet_makeProfessional();
    $audit = cet_makeAudit($other, ['status' => CommissionExportAudit::STATUS_QUEUED]);

    test()->getJson("/api/stripe/exports/commission/{$audit->id}")
        ->assertNotFound();
});

it('the old sync transactions route is gone (returns 404)', function () {
    cet_actingAsBrand();

    test()->get('/api/stripe/exports/transactions.csv?role=brand')
        ->assertNotFound();
});

it('the sync payouts route is still registered (not broken by regex narrowing)', function () {
    cet_actingAsBrand();

    // Use call() so the TestResponse wraps the response correctly regardless of
    // whether it's a StreamedResponse or regular response. Assert not 404.
    $response = test()->call('GET', '/api/stripe/exports/payouts.csv', ['role' => 'brand']);
    expect($response->getStatusCode())->not->toBe(404, 'Sync payouts export route should still resolve');
});

// ─── CAPE-1: Stripe Connect status gate ───────────────────────────────────────

it('POST returns 422 when professional has no active Stripe Connect', function () {
    Bus::fake();

    // Incomplete onboarding — stripe_connect_status is not 'active'.
    cet_actingAsBrand(['stripe_connect_status' => 'incomplete']);

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'csv'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Complete Stripe Connect onboarding before exporting transactions.']);

    Bus::assertNothingDispatched();
});

it('POST returns 422 when professional has no Stripe Connect at all', function () {
    Bus::fake();

    // No stripe_connect_status set (new account pre-onboarding).
    cet_actingAsBrand(['stripe_connect_status' => null]);

    test()->postJson('/api/stripe/exports/transactions', ['format' => 'csv'])
        ->assertStatus(422)
        ->assertJson(['message' => 'Complete Stripe Connect onboarding before exporting transactions.']);

    Bus::assertNothingDispatched();
});
