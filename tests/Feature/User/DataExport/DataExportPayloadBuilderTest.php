<?php

use App\Models\Core\User\User;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
});

function seedProForPayload(string $id, string $email = 'jane@example.com', ?string $authUserId = null): User
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => $authUserId ?? (string) Str::uuid(),
        'handle' => 'jane',
        'handle_lc' => 'jane',
        'display_name' => 'Jane',
        'primary_email' => $email,
        'status' => 'active',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    return User::find($id);
}

it('exports waitlist signups matched by email_lc', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        [
            'id' => (string) Str::uuid(),
            'name' => 'Jane Owner',
            'email' => 'Jane@Example.com',     // mixed-case to prove email_lc match works
            'email_lc' => 'jane@example.com',
            'phone' => '+61400000001',
            'applicant_type' => 'professional',
            'industry' => 'mens_grooming',
            'pilot_program_opt_in' => 1,
            'consent_source' => 'waitlist_form',
            'last_submitted_at' => '2026-02-01T00:00:00Z',
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'name' => 'Someone Else',
            'email' => 'other@example.com',
            'email_lc' => 'other@example.com',
            'phone' => '+61400000002',
            'applicant_type' => 'professional',
            'industry' => 'beauty_products',
            'pilot_program_opt_in' => 0,
            'consent_source' => 'waitlist_form',
            'last_submitted_at' => '2026-02-02T00:00:00Z',
            'created_at' => '2026-02-02T00:00:00Z',
            'updated_at' => '2026-02-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['waitlist'])->toHaveCount(1);
    expect($payload['waitlist'][0]['name'])->toBe('Jane Owner');
    expect($payload['waitlist'][0]['email'])->toBe('Jane@Example.com');
});

it('waitlist lookup trims whitespace on primary_email before normalising', function () {
    // Defensive: if primary_email has stray whitespace (legacy import), the
    // DSAR must still match the trimmed email_lc that writers stored.
    $pro = seedProForPayload((string) Str::uuid(), '  Jane@Example.com  ');

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Jane Owner',
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'phone' => '+61400000001',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'pilot_program_opt_in' => 1,
        'consent_source' => 'waitlist_form',
        'last_submitted_at' => '2026-02-01T00:00:00Z',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['waitlist'])->toHaveCount(1);
});

it('exports owned, global, AND cross-professional email_subscriptions for the user (P1-15)', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        // Owned row — joined by user_id.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'email_lc' => 'jane@example.com',
            'list_key' => 'marketing',
            'email' => 'jane@example.com',
            'full_name' => 'Jane',
            'status' => 'subscribed',
            'subscribed_at' => '2026-03-01T00:00:00Z',
            'consent_source' => 'signup',
            'created_at' => '2026-03-01T00:00:00Z',
        ],
        // Global "sidest_updates" row — user_id IS NULL, keyed by email_lc only.
        [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'email_lc' => 'jane@example.com',
            'list_key' => 'sidest_updates',
            'email' => 'Jane@Example.com',
            'full_name' => 'Jane',
            'status' => 'subscribed',
            'subscribed_at' => '2026-03-02T00:00:00Z',
            'consent_source' => 'bootstrap',
            'created_at' => '2026-03-02T00:00:00Z',
        ],
        // Cross-professional row — Jane subscribed to ANOTHER pro's newsletter.
        // Row is keyed to a different user_id but contains Jane's email
        // and consent timestamp. MUST appear in Jane's DSAR.
        [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'email_lc' => 'jane@example.com',
            'list_key' => 'marketing',
            'email' => 'jane@example.com',
            'full_name' => 'Jane',
            'status' => 'subscribed',
            'subscribed_at' => '2026-03-04T00:00:00Z',
            'consent_source' => 'public_site_form',
            'created_at' => '2026-03-04T00:00:00Z',
        ],
        // Unrelated user's subscription — must NOT appear.
        [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'email_lc' => 'other@example.com',
            'list_key' => 'sidest_updates',
            'email' => 'other@example.com',
            'full_name' => 'Other',
            'status' => 'subscribed',
            'subscribed_at' => '2026-03-03T00:00:00Z',
            'consent_source' => 'bootstrap',
            'created_at' => '2026-03-03T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['email_subscriptions'])->toHaveCount(3);

    // Plucking email_lc (now in the SELECT) gives a real check that the
    // unrelated row is excluded — replaces the previous vacuous assertion that
    // plucked a field not in the SELECT list.
    $emailLcs = collect($payload['email_subscriptions'])->pluck('email_lc')->all();
    expect($emailLcs)->each->toBe('jane@example.com');
    expect(collect($payload['email_subscriptions'])->pluck('list_key')->sort()->values()->all())
        ->toBe(['marketing', 'marketing', 'sidest_updates']);
});

it('exports handle_change_log entries with actor_id redacted to coarse actor_kind', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $otherId = (string) Str::uuid();
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('audit.handle_change_log')->insert([
        // Self-rename — actor is the user themselves.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'old_handle' => 'jane-old',
            'new_handle' => 'jane',
            'reason' => 'rename',
            'actor_id' => $pro->id,
            'changed_at' => '2026-02-15T00:00:00Z',
        ],
        // System-initiated reclaim (no actor).
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'old_handle' => null,
            'new_handle' => 'jane-old',
            'reason' => 'reclaim',
            'actor_id' => null,
            'changed_at' => '2026-01-10T00:00:00Z',
        ],
        // Staff-initiated rename — actor_id is the staff member's UUID.
        // The export must surface a coarse marker, NOT the staff UUID.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'old_handle' => 'jane',
            'new_handle' => 'jane-final',
            'reason' => 'staff_rename',
            'actor_id' => $staffId,
            'changed_at' => '2026-02-20T00:00:00Z',
        ],
        // Different user's row — must not appear.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'old_handle' => 'not-jane',
            'new_handle' => 'not-jane-2',
            'reason' => 'rename',
            'actor_id' => $otherId,
            'changed_at' => '2026-02-21T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['audit']['handle_change_log'])->toHaveCount(3);

    // Every row exposes actor_kind (self / system / staff) but NOT the raw
    // actor_id (which would leak a staff member's identifier).
    foreach ($payload['audit']['handle_change_log'] as $row) {
        expect($row)->not->toHaveKey('actor_id');
        expect($row)->toHaveKey('actor_kind');
    }

    $actorKinds = collect($payload['audit']['handle_change_log'])->pluck('actor_kind')->sort()->values()->all();
    expect($actorKinds)->toBe(['self', 'staff', 'system']);
});

it('exports lead_submissions in the payload (P2-38 regression guard)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('analytics.lead_submissions')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'occurred_at' => '2026-04-01T00:00:00Z',
            'outcome' => 'submitted',
            'subdomain' => 'jane',
            'referrer' => 'https://google.com',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'occurred_at' => '2026-04-02T00:00:00Z',
            'outcome' => 'abandoned',
            'subdomain' => 'jane',
            'referrer' => null,
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('lead_submissions');
    expect($payload['lead_submissions'])->toHaveCount(2);

    $outcomes = collect($payload['lead_submissions'])->pluck('outcome')->sort()->values()->all();
    expect($outcomes)->toBe(['abandoned', 'submitted']);
});

it('redacts ip and user_agent fields from waitlist and handle_change_log rows', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Jane Owner',
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'phone' => '+61400000001',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'pilot_program_opt_in' => 1,
        'consent_source' => 'waitlist_form',
        'consent_ip_hash' => 'sha256-abc',
        'consent_user_agent' => 'Mozilla/5.0',
        'last_submitted_at' => '2026-02-01T00:00:00Z',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('audit.handle_change_log')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'old_handle' => 'jane-old',
        'new_handle' => 'jane',
        'reason' => 'rename',
        'ip_address' => '203.0.113.42',
        'user_agent' => 'Mozilla/5.0',
        'changed_at' => '2026-02-15T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $waitlistRow = $payload['waitlist'][0];
    expect($waitlistRow)->not->toHaveKey('consent_ip_hash');
    expect($waitlistRow)->not->toHaveKey('consent_user_agent');
    expect($waitlistRow)->not->toHaveKey('email_lc');
    expect($waitlistRow['name'])->toBe('Jane Owner');

    $handleRow = $payload['audit']['handle_change_log'][0];
    expect($handleRow)->not->toHaveKey('ip_address');
    expect($handleRow)->not->toHaveKey('user_agent');
    expect($handleRow['new_handle'])->toBe('jane');
});

it('falls back to deletion_audit email_snapshot when primary_email has been pseudonymised', function () {
    // After AccountDeletionService::pseudonymiseAccountPii() rewrites
    // primary_email to "deleted+{id}@partna.au", a DSAR must still be able to
    // surface the user's pre-account waitlist + global subscriptions. We use
    // the email snapshot in audit.user_deletion_audit (written BEFORE
    // pseudonymisation) as the fallback.
    $proId = (string) Str::uuid();
    $pro = seedProForPayload($proId, 'deleted+'.$proId.'@partna.au');

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'requested',
            'actor_type' => 'professional',
            'created_at' => '2026-04-10T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Jane Owner',
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'phone' => '+61400000001',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'pilot_program_opt_in' => 1,
        'consent_source' => 'waitlist_form',
        'last_submitted_at' => '2026-02-01T00:00:00Z',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // Without the fallback, the lookup would be "deleted+{id}@partna.au" and
    // the waitlist section would be empty.
    expect($payload['waitlist'])->toHaveCount(1);
    expect($payload['waitlist'][0]['email'])->toBe('jane@example.com');
});

// SEM-3 regression: the old resolveLookupEmail was missing EVENT_CONFIRMED and sorted ASC,
// causing admin-initiated deletions (confirmed row, no requested row) to produce null → empty DSAR.
it('SEM-3: resolves email from confirmed-only audit row (admin-initiated deletion, no requested row)', function () {
    // An admin-initiated deletion writes EVENT_ADMIN_INITIATED then EVENT_CONFIRMED
    // without a preceding EVENT_REQUESTED. The old resolveLookupEmail excluded
    // EVENT_CONFIRMED entirely — this test proves the shared trait handles it.
    $proId = (string) Str::uuid();
    $pro = seedProForPayload($proId, 'deleted+'.$proId.'@partna.au');

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'confirmed',       // EVENT_CONFIRMED — the only row present
            'actor_type' => 'professional',
            'created_at' => '2026-04-12T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        'id' => (string) Str::uuid(),
        'name' => 'Jane Owner',
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'phone' => '+61400000001',
        'applicant_type' => 'professional',
        'industry' => 'mens_grooming',
        'pilot_program_opt_in' => 1,
        'consent_source' => 'waitlist_form',
        'last_submitted_at' => '2026-02-01T00:00:00Z',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // If SEM-3 regresses, confirmed-only rows return null snapshot → empty waitlist.
    expect($payload['waitlist'])->toHaveCount(1);
    expect($payload['waitlist'][0]['email'])->toBe('jane@example.com');
});

it('SEM-3: resolveDeletedAccountEmail returns most-recent snapshot when multiple audit rows exist (DESC order)', function () {
    // The old resolveLookupEmail used orderBy ASC — if the most recent snapshot
    // is the most complete one (e.g. confirmed row overrides a stale requested
    // row), DESC is correct. This test seeds two rows with different emails to
    // prove DESC wins.
    $proId = (string) Str::uuid();
    $pro = seedProForPayload($proId, 'deleted+'.$proId.'@partna.au');

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'old-jane@example.com',
            'event' => 'requested',
            'actor_type' => 'professional',
            'created_at' => '2026-04-10T00:00:00Z',   // earlier
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $proId,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'confirmed',
            'actor_type' => 'professional',
            'created_at' => '2026-04-12T00:00:00Z',   // later — should win
        ],
    ]);

    DB::connection('pgsql')->table('core.waitlist_signups')->insert([
        [
            'id' => (string) Str::uuid(),
            'name' => 'Jane Final',
            'email' => 'jane@example.com',
            'email_lc' => 'jane@example.com',
            'phone' => '+61400000001',
            'applicant_type' => 'professional',
            'industry' => 'mens_grooming',
            'pilot_program_opt_in' => 1,
            'consent_source' => 'waitlist_form',
            'last_submitted_at' => '2026-02-01T00:00:00Z',
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'name' => 'Jane Old',
            'email' => 'old-jane@example.com',
            'email_lc' => 'old-jane@example.com',
            'phone' => '+61400000002',
            'applicant_type' => 'professional',
            'industry' => 'mens_grooming',
            'pilot_program_opt_in' => 0,
            'consent_source' => 'waitlist_form',
            'last_submitted_at' => '2026-01-01T00:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // DESC order means the confirmed row (jane@example.com, 2026-04-12) wins.
    // If ASC regresses, the waitlist would return 'old-jane@example.com' instead.
    expect($payload['waitlist'])->toHaveCount(1);
    expect($payload['waitlist'][0]['email'])->toBe('jane@example.com');
    expect($payload['waitlist'][0]['name'])->toBe('Jane Final');
});

it('exports notifications.messages and notifications.receipts addressed to the user', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $otherId = (string) Str::uuid();
    $notifId = (string) Str::uuid();

    DB::connection('pgsql')->table('notifications.notifications')->insert([
        [
            'id' => $notifId,
            'user_id' => $pro->id,
            'type' => 'Warning',
            'title' => 'Action required: verify your email',
            'body' => 'Hi Jane, please verify your email by Friday.',
            'severity' => 'warning',
            'created_at' => '2026-04-15T00:00:00Z',
            'updated_at' => '2026-04-15T00:00:00Z',
        ],
        // Broadcast (NULL user_id) — not user-specific, excluded.
        [
            'id' => (string) Str::uuid(),
            'user_id' => null,
            'type' => 'Info',
            'title' => 'Site-wide announcement',
            'body' => 'Everyone gets this one.',
            'severity' => 'info',
            'created_at' => '2026-04-16T00:00:00Z',
            'updated_at' => '2026-04-16T00:00:00Z',
        ],
        // Different user — excluded.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'type' => 'Info',
            'title' => 'Not for Jane',
            'body' => 'For someone else.',
            'severity' => 'info',
            'created_at' => '2026-04-17T00:00:00Z',
            'updated_at' => '2026-04-17T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('notifications.notification_receipts')->insert([
        [
            'id' => (string) Str::uuid(),
            'notification_id' => $notifId,
            'user_id' => $pro->id,
            'read_at' => '2026-04-15T12:00:00Z',
            'dismissed_at' => null,
            'created_at' => '2026-04-15T12:00:00Z',
            'updated_at' => '2026-04-15T12:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['notifications']['messages'])->toHaveCount(1);
    expect($payload['notifications']['messages'][0]['title'])->toBe('Action required: verify your email');
    expect($payload['notifications']['receipts'])->toHaveCount(1);
    expect($payload['notifications']['receipts'][0]['read_at'])->toBe('2026-04-15T12:00:00Z');
});

it('exports handle_aliases and subdomain_aliases for the user', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();
    $otherSiteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        ['id' => $siteId, 'user_id' => $pro->id, 'subdomain' => 'jane', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => $otherSiteId, 'user_id' => (string) Str::uuid(), 'subdomain' => 'someone', 'created_at' => '2026-01-01T00:00:00Z'],
    ]);

    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'handle' => 'jane-old',
            'reclaim_until' => '2026-05-01T00:00:00Z',
            'expires_at' => '2026-08-01T00:00:00Z',
            'created_at' => '2026-02-15T00:00:00Z',
            'updated_at' => '2026-02-15T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'handle' => 'not-jane',
            'reclaim_until' => null,
            'expires_at' => null,
            'created_at' => '2026-02-16T00:00:00Z',
            'updated_at' => '2026-02-16T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        [
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'subdomain' => 'jane-old',
            'reclaim_until' => '2026-05-01T00:00:00Z',
            'expires_at' => '2026-08-01T00:00:00Z',
            'created_at' => '2026-02-15T00:00:00Z',
            'updated_at' => '2026-02-15T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'site_id' => $otherSiteId,
            'subdomain' => 'someone-old',
            'reclaim_until' => null,
            'expires_at' => null,
            'created_at' => '2026-02-16T00:00:00Z',
            'updated_at' => '2026-02-16T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['audit']['handle_aliases'])->toHaveCount(1);
    expect($payload['audit']['handle_aliases'][0]['handle'])->toBe('jane-old');
    expect($payload['audit']['subdomain_aliases'])->toHaveCount(1);
    expect($payload['audit']['subdomain_aliases'][0]['subdomain'])->toBe('jane-old');
});

it('exports user_deletion_audit rows for the user', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'requested',
            'actor_type' => 'professional',
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2026-04-10T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'cancelled',
            'actor_type' => 'professional',
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2026-04-11T00:00:00Z',
        ],
        // Different user — excluded.
        [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'professional_handle_snapshot' => 'other',
            'professional_email_snapshot' => 'other@example.com',
            'event' => 'requested',
            'actor_type' => 'professional',
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => '2026-04-12T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['audit']['deletion_audit'])->toHaveCount(2);
    expect(collect($payload['audit']['deletion_audit'])->pluck('event')->sort()->values()->all())
        ->toBe(['cancelled', 'requested']);
});

it('exports auth.factor_events joined by auth_user_id', function () {
    $authUserId = (string) Str::uuid();
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com', $authUserId);
    $otherAuthId = (string) Str::uuid();

    DB::connection('pgsql')->table('audit.auth_factor_events')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $authUserId,
            'event_type' => 'enroll_completed',
            'factor_type' => 'totp',
            'ip' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2026-05-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $authUserId,
            'event_type' => 'verify_success',
            'factor_type' => 'totp',
            'ip' => null,
            'user_agent' => null,
            'created_at' => '2026-05-02T00:00:00Z',
        ],
        // Different user — excluded.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherAuthId,
            'event_type' => 'enroll_completed',
            'factor_type' => 'totp',
            'ip' => null,
            'user_agent' => null,
            'created_at' => '2026-05-03T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['auth']['factor_events'])->toHaveCount(2);
});

it('exports user_confirmation_preferences for the user', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('core.user_confirmation_preferences')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'action_key' => 'delete_customer',
            'skip_confirmation' => 1,
            'created_at' => '2026-04-01T00:00:00Z',
            'updated_at' => '2026-04-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'action_key' => 'delete_service',
            'skip_confirmation' => 0,
            'created_at' => '2026-04-02T00:00:00Z',
            'updated_at' => '2026-04-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['ui_preferences']['confirmation_preferences'])->toHaveCount(1);
    expect($payload['ui_preferences']['confirmation_preferences'][0]['action_key'])->toBe('delete_customer');
});

it('exports feedback submissions excluding ip_hash and user_agent fingerprints (#P1-06)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('core.feedback')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'kind' => 'bug',
            'severity' => 'high',
            'message' => 'The save button is broken.',
            'page_url' => 'https://dashboard.partna.au/site',
            'user_agent' => 'Mozilla/5.0',
            'ip_hash' => 'sha256-deadbeef',
            'status' => 'new',
            'source' => 'dashboard',
            'internal_notes' => '[]',
            'tags' => '[]',
            'created_at' => '2026-04-01T00:00:00Z',
            'updated_at' => '2026-04-01T00:00:00Z',
        ],
        // Different user — must not appear. All columns present to match SQLite multi-row insert requirements.
        [
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'kind' => 'idea',
            'severity' => null,
            'message' => 'Not Jane\'s feedback.',
            'page_url' => null,
            'user_agent' => null,
            'ip_hash' => null,
            'status' => 'new',
            'source' => 'dashboard',
            'internal_notes' => '[]',
            'tags' => '[]',
            'created_at' => '2026-04-02T00:00:00Z',
            'updated_at' => '2026-04-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('feedback');
    expect($payload['feedback'])->toHaveCount(1);
    expect($payload['feedback'][0]['kind'])->toBe('bug');
    expect($payload['feedback'][0]['message'])->toBe('The save button is broken.');

    // Technical fingerprints must be redacted.
    expect($payload['feedback'][0])->not->toHaveKey('ip_hash');
    expect($payload['feedback'][0])->not->toHaveKey('user_agent');
});

it('exports content_reports (cases against user and signals filed by user) excluding reporter_ip_hash (#P1-07)', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');
    $caseId = (string) Str::uuid();
    $otherCaseId = (string) Str::uuid();

    // A case where the user's content was reported.
    DB::connection('pgsql')->table('moderation.cases')->insert([
        [
            'id' => $caseId,
            'case_type' => 'content_report',
            'reportable_type' => 'Site',
            'reportable_id' => (string) Str::uuid(),
            'reportable_owner_user_id' => $pro->id,
            'severity' => 2,
            'status' => 'open',
            'signal_count' => 1,
            'auto_actioned' => 0,
            'created_at' => '2026-05-01T00:00:00Z',
            'updated_at' => '2026-05-01T00:00:00Z',
        ],
        // Case for a different user — must not appear.
        [
            'id' => $otherCaseId,
            'case_type' => 'content_report',
            'reportable_type' => 'Site',
            'reportable_id' => (string) Str::uuid(),
            'reportable_owner_user_id' => (string) Str::uuid(),
            'severity' => 1,
            'status' => 'resolved',
            'signal_count' => 1,
            'auto_actioned' => 0,
            'created_at' => '2026-05-02T00:00:00Z',
            'updated_at' => '2026-05-02T00:00:00Z',
        ],
    ]);

    // A signal filed by the user (by user_id).
    DB::connection('pgsql')->table('moderation.case_signals')->insert([
        [
            'id' => (string) Str::uuid(),
            'case_id' => $otherCaseId,
            'signal_source' => 'content_report',
            'reporter_user_id' => $pro->id,
            'reporter_email' => 'jane@example.com',
            'reporter_ip_hash' => 'sha256-reporterip',
            'reason_code' => 'spam',
            'reason_details' => 'This is spam content.',
            'dedup_hash' => 'abc123',
            'created_at' => '2026-05-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('content_reports');
    expect($payload['content_reports'])->toHaveCount(2);

    $types = collect($payload['content_reports'])->pluck('record_type')->sort()->values()->all();
    expect($types)->toBe(['filed_by_me', 'reported_against_me']);

    $caseRow = collect($payload['content_reports'])->firstWhere('record_type', 'reported_against_me');
    expect($caseRow['case_type'])->toBe('content_report');
    expect($caseRow['status'])->toBe('open');

    $signalRow = collect($payload['content_reports'])->firstWhere('record_type', 'filed_by_me');
    expect($signalRow['reason_code'])->toBe('spam');
    // Technical fingerprints must be redacted.
    expect($signalRow)->not->toHaveKey('reporter_ip_hash');
    expect($signalRow)->not->toHaveKey('dedup_hash');
});

it('exports design_kit for the user\'s site, yielding an empty result when no site exists (#P3-20)', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'color_accent' => '#FF5733',
        'color_bg' => null,
        'typography_font_heading' => 'Playfair Display',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('design_kit');
    expect($payload['design_kit'])->toHaveCount(1);
    expect($payload['design_kit'][0]['site_id'])->toBe($siteId);
    expect($payload['design_kit'][0]['color_accent'])->toBe('#FF5733');
    expect($payload['design_kit'][0]['typography_font_heading'])->toBe('Playfair Display');

    // User with no site — design_kit should be empty, not an error.
    $pro2 = seedProForPayload((string) Str::uuid(), 'other@example.com');
    $payload2 = app(DataExportPayloadBuilder::class)->build($pro2->id);
    expect($payload2['design_kit'])->toBeEmpty();
});
