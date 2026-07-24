<?php

use App\Models\Core\User\User;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use Illuminate\Support\Arr;
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

/*
| Media is scoped through the user's SITE (site_media.site_id), not a user_id
| column — site_media has no user_id in production. Before this test the
| section selected width/height and filtered on user_id, none of which exist
| in Postgres, so the export 500'd in prod while staying green here: SQLite
| silently reinterprets an unknown double-quoted identifier as a string
| literal instead of erroring, so `where "user_id" = ?` just matched nothing.
| Asserting the row actually COMES BACK is what makes that visible.
*/
it('exports site media scoped through the user site, not a user_id column', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();
    $otherSiteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        ['id' => $siteId, 'user_id' => $pro->id, 'subdomain' => 'jane', 'created_at' => '2026-01-01T00:00:00Z'],
        ['id' => $otherSiteId, 'user_id' => (string) Str::uuid(), 'subdomain' => 'someone', 'created_at' => '2026-01-01T00:00:00Z'],
    ]);

    DB::connection('pgsql')->table('site.site_media')->insert([
        [
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'pool' => 'gallery',
            'purpose' => 'portfolio',
            'path' => 'media/jane/one.jpg',
            'media_type' => 'image',
            'original_filename' => 'holiday-headshot.jpg',
            'caption' => 'Jane at work',
            'alt_text' => 'Portrait of Jane',
            'created_at' => '2026-03-01T00:00:00Z',
        ],
        [
            // Another user's media — must NOT leak into Jane's export.
            'id' => (string) Str::uuid(),
            'site_id' => $otherSiteId,
            'pool' => 'gallery',
            'purpose' => 'portfolio',
            'path' => 'media/someone/two.jpg',
            'media_type' => 'image',
            'original_filename' => 'not-jane.jpg',
            'caption' => 'Not Jane',
            'alt_text' => 'Someone else',
            'created_at' => '2026-03-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['media']['site_media'])->toHaveCount(1);
    expect($payload['media']['site_media'][0]['path'])->toBe('media/jane/one.jpg');
    expect($payload['media']['site_media'][0]['caption'])->toBe('Jane at work');
    // original_filename is user-supplied and can carry personal detail, so a
    // DSAR must surface it.
    expect($payload['media']['site_media'][0]['original_filename'])->toBe('holiday-headshot.jpg');
});

it('exports the workplace fields intact', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('site.workplaces')->insert([
        'site_id' => $siteId,
        'name' => "Jane's Salon",
        'address_line1' => '123 Example St',
        'city' => 'Sydney',
        'phone' => '+61 2 5550 1234',
        'website' => 'https://janes-salon.example.com',
        'category' => 'Hair Salon',
        'created_at' => '2026-03-01T00:00:00Z',
        'updated_at' => '2026-03-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['site']['workplace'])->not->toBeNull();
    expect($payload['site']['workplace']['name'])->toBe("Jane's Salon")
        ->and($payload['site']['workplace']['address_line1'])->toBe('123 Example St')
        ->and($payload['site']['workplace']['city'])->toBe('Sydney')
        ->and($payload['site']['workplace']['phone'])->toBe('+61 2 5550 1234')
        ->and($payload['site']['workplace']['website'])->toBe('https://janes-salon.example.com')
        ->and($payload['site']['workplace']['category'])->toBe('Hair Salon');
});

it('exports early access signups matched by email_lc', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        [
            'id' => (string) Str::uuid(),
            'email' => 'Jane@Example.com',     // mixed-case to prove email_lc match works
            'email_lc' => 'jane@example.com',
            'type' => 'partna',
            'workplace_or_industry' => 'Jane Owner Studio',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'email' => 'other@example.com',
            'email_lc' => 'other@example.com',
            'type' => 'business',
            'workplace_or_industry' => 'Someone Else Co',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-02-02T00:00:00Z',
            'updated_at' => '2026-02-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['early_access'])->toHaveCount(1);
    expect($payload['early_access'][0]['workplace_or_industry'])->toBe('Jane Owner Studio');
    expect($payload['early_access'][0]['email'])->toBe('Jane@Example.com');
});

it('early access lookup trims whitespace on primary_email before normalising', function () {
    // Defensive: if primary_email has stray whitespace (legacy import), the
    // DSAR must still match the trimmed email_lc that writers stored.
    $pro = seedProForPayload((string) Str::uuid(), '  Jane@Example.com  ');

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Owner Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['early_access'])->toHaveCount(1);
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

it('redacts the staff recipient email and staff UUID from the data_export_audit section (PRIV-1)', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');
    $staffId = (string) Str::uuid();

    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        // Self-service export sent to the professional — recipient_email is their own.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'triggered_by' => 'self',
            'triggered_by_staff_id' => null,
            'recipient_email' => 'jane@example.com',
            'send_to' => 'professional',
            'status' => 'completed',
            'created_at' => '2026-05-01T00:00:00Z',
        ],
        // Staff-triggered export delivered to the staff member — recipient_email
        // holds THEIR work email, a third party who never consented to disclosure.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'triggered_by' => 'staff',
            'triggered_by_staff_id' => $staffId,
            'recipient_email' => 'support-agent@partna.au',
            'send_to' => 'staff',
            'status' => 'completed',
            'created_at' => '2026-05-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $rows = $payload['audit']['data_export_audit'];
    expect($rows)->toHaveCount(2);

    // The staff UUID is never disclosed — triggered_by already records self-vs-staff.
    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('triggered_by_staff_id');
    }

    $proRow = collect($rows)->firstWhere('send_to', 'professional');
    $staffRow = collect($rows)->firstWhere('send_to', 'staff');

    // The professional's own recipient email is disclosed...
    expect($proRow['recipient_email'])->toBe('jane@example.com');
    // ...but the staff member's email is redacted.
    expect($staffRow['recipient_email'])->toBe('[redacted]');
    expect($staffRow['recipient_email'])->not->toBe('support-agent@partna.au');
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

it('redacts ip and user_agent fields from early access and handle_change_log rows', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Owner Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'invite_token_hash' => 'sha256-tokenhash',
        'consent_ip_hash' => 'sha256-abc',
        'consent_user_agent' => 'Mozilla/5.0',
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

    $earlyAccessRow = $payload['early_access'][0];
    expect($earlyAccessRow)->not->toHaveKey('consent_ip_hash');
    expect($earlyAccessRow)->not->toHaveKey('consent_user_agent');
    expect($earlyAccessRow)->not->toHaveKey('email_lc');
    // Credential material — never exported.
    expect($earlyAccessRow)->not->toHaveKey('invite_token_hash');
    expect($earlyAccessRow['workplace_or_industry'])->toBe('Jane Owner Studio');

    $handleRow = $payload['audit']['handle_change_log'][0];
    expect($handleRow)->not->toHaveKey('ip_address');
    expect($handleRow)->not->toHaveKey('user_agent');
    expect($handleRow['new_handle'])->toBe('jane');
});

it('falls back to deletion_audit email_snapshot when primary_email has been pseudonymised', function () {
    // After AccountDeletionService::pseudonymiseAccountPii() rewrites
    // primary_email to "deleted+{id}@partna.au", a DSAR must still be able to
    // surface the user's pre-account early access signup + global subscriptions.
    // We use the email snapshot in audit.user_deletion_audit (written BEFORE
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

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Owner Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // Without the fallback, the lookup would be "deleted+{id}@partna.au" and
    // the early_access section would be empty.
    expect($payload['early_access'])->toHaveCount(1);
    expect($payload['early_access'][0]['email'])->toBe('jane@example.com');
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

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        'id' => (string) Str::uuid(),
        'email' => 'jane@example.com',
        'email_lc' => 'jane@example.com',
        'type' => 'partna',
        'workplace_or_industry' => 'Jane Owner Studio',
        'platforms' => '[]',
        'status' => 'waitlist',
        'source' => 'marketing',
        'created_at' => '2026-02-01T00:00:00Z',
        'updated_at' => '2026-02-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // If SEM-3 regresses, confirmed-only rows return null snapshot → empty early_access.
    expect($payload['early_access'])->toHaveCount(1);
    expect($payload['early_access'][0]['email'])->toBe('jane@example.com');
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

    DB::connection('pgsql')->table('core.early_access_signups')->insert([
        [
            'id' => (string) Str::uuid(),
            'email' => 'jane@example.com',
            'email_lc' => 'jane@example.com',
            'type' => 'partna',
            'workplace_or_industry' => 'Jane Final Studio',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'email' => 'old-jane@example.com',
            'email_lc' => 'old-jane@example.com',
            'type' => 'partna',
            'workplace_or_industry' => 'Jane Old Studio',
            'platforms' => '[]',
            'status' => 'waitlist',
            'source' => 'marketing',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($proId);

    // DESC order means the confirmed row (jane@example.com, 2026-04-12) wins.
    // If ASC regresses, the early_access lookup would return 'old-jane@example.com' instead.
    expect($payload['early_access'])->toHaveCount(1);
    expect($payload['early_access'][0]['email'])->toBe('jane@example.com');
    expect($payload['early_access'][0]['workplace_or_industry'])->toBe('Jane Final Studio');
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

it('redacts staff IP/UA from deletion_audit but keeps the subject\'s own (gdpr-deletion-export/PRIV-1)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'requested',
            'actor_type' => 'professional',
            'reason' => null,
            'ip_address' => '1.1.1.1',
            'user_agent' => 'ProBrowser/1.0',
            'created_at' => '2026-04-10T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'confirmed',
            'actor_type' => 'professional',
            'reason' => null,
            'ip_address' => '1.1.1.1',
            'user_agent' => 'ProBrowser/1.0',
            'created_at' => '2026-04-11T00:00:00Z',
        ],
        // Admin-initiated: ip_address/user_agent here belong to the STAFF
        // member's own request, not the data subject's — must be redacted.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'admin_initiated',
            'actor_type' => 'staff_admin',
            'reason' => 'GDPR erasure request, ticket #4821',
            'ip_address' => '9.9.9.9',
            'user_agent' => 'StaffBrowser/1.0',
            'created_at' => '2026-04-12T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'professional_handle_snapshot' => 'jane',
            'professional_email_snapshot' => 'jane@example.com',
            'event' => 'purge_failed',
            'actor_type' => 'system',
            'reason' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => '2026-04-13T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);
    $rows = collect($payload['audit']['deletion_audit'])->keyBy('event');

    // Self-service rows: the subject's own IP/UA, kept.
    expect($rows['requested']['ip_address'])->toBe('1.1.1.1');
    expect($rows['confirmed']['ip_address'])->toBe('1.1.1.1');
    expect($rows['requested']['actor_kind'])->toBe('self');

    // Staff row: redacted, but `reason` (about the subject, Article 15
    // relevant) is kept.
    expect($rows['admin_initiated']['ip_address'])->toBeNull();
    expect($rows['admin_initiated']['user_agent'])->toBeNull();
    expect($rows['admin_initiated']['actor_kind'])->toBe('staff');
    expect($rows['admin_initiated']['reason'])->toBe('GDPR erasure request, ticket #4821');

    // System row.
    expect($rows['purge_failed']['actor_kind'])->toBe('system');

    // The strongest assertion: the staff member's IP must not leak through
    // ANY section of the export, not just this one.
    expect(json_encode($payload))->not->toContain('9.9.9.9');
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

it('exports feedback type/area/target, including the NULL case (PRIV-6)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('core.feedback')->insert([
        [
            // Fully populated — the OV-D dashboard taxonomy fields.
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'kind' => 'bug',
            'severity' => 'high',
            'type' => 'error',
            'area' => 'dashboard',
            'target' => json_encode(['page' => 'site-editor']),
            'message' => 'The save button is broken.',
            'status' => 'new',
            'source' => 'dashboard',
            'internal_notes' => '[]',
            'tags' => '[]',
            'created_at' => '2026-04-01T00:00:00Z',
            'updated_at' => '2026-04-01T00:00:00Z',
        ],
        [
            // All three columns are nullable — a legacy row predating OV-D has them NULL.
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'kind' => 'idea',
            'severity' => null,
            'type' => null,
            'area' => null,
            'target' => null,
            'message' => 'Legacy submission with no taxonomy.',
            'status' => 'new',
            'source' => 'dashboard',
            'internal_notes' => '[]',
            'tags' => '[]',
            'created_at' => '2026-04-02T00:00:00Z',
            'updated_at' => '2026-04-02T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['feedback'])->toHaveCount(2);

    $withTaxonomy = collect($payload['feedback'])->firstWhere('type', 'error');
    expect($withTaxonomy)->not->toBeNull()
        ->and($withTaxonomy['area'])->toBe('dashboard')
        ->and($withTaxonomy['target'])->toBe(json_encode(['page' => 'site-editor']));

    $legacy = collect($payload['feedback'])->firstWhere('kind', 'idea');
    expect($legacy['type'])->toBeNull()
        ->and($legacy['area'])->toBeNull()
        ->and($legacy['target'])->toBeNull();
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

it('SEM-1: matches a legacy mixed-case + whitespace reporter_email in the export', function () {
    $pro = seedProForPayload((string) Str::uuid(), 'jane@example.com');
    $caseId = (string) Str::uuid();

    DB::connection('pgsql')->table('moderation.cases')->insert([
        'id' => $caseId,
        'case_type' => 'content_report',
        'reportable_type' => 'Site',
        'reportable_id' => (string) Str::uuid(),
        'reportable_owner_user_id' => (string) Str::uuid(),
        'severity' => 2,
        'status' => 'open',
        'signal_count' => 1,
        'auto_actioned' => 0,
        'created_at' => '2026-05-01T00:00:00Z',
        'updated_at' => '2026-05-01T00:00:00Z',
    ]);

    // Filed by email only (no reporter_user_id), stored mixed-case + padded —
    // i.e. before normalisation-on-write existed. The lowercased export lookup
    // must still match it.
    DB::connection('pgsql')->table('moderation.case_signals')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => $caseId,
        'signal_source' => 'content_report',
        'reporter_user_id' => null,
        'reporter_email' => '  JANE@Example.com ',
        'reason_code' => 'spam',
        'reason_details' => 'mixed case + whitespace',
        'created_at' => '2026-05-02T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $filed = collect($payload['content_reports'])->where('record_type', 'filed_by_me')->values();
    expect($filed)->toHaveCount(1);
    expect($filed->first()['reason_code'])->toBe('spam');
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

    // typography_font_family is a live column (typography_font_heading/_body were
    // dropped by 20260603000001 — see the site.design_kits DDL drift note in
    // DataExportTestCase::boot()).
    DB::connection('pgsql')->table('site.design_kits')->insert([
        'site_id' => $siteId,
        'color_accent' => '#FF5733',
        'typography_font_family' => 'Playfair Display',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('design_kit');
    expect($payload['design_kit'])->toHaveCount(1);
    expect($payload['design_kit'][0]['site_id'])->toBe($siteId);
    expect($payload['design_kit'][0]['color_accent'])->toBe('#FF5733');
    expect($payload['design_kit'][0]['typography_font_family'])->toBe('Playfair Display');

    // User with no site — design_kit should be empty, not an error.
    $pro2 = seedProForPayload((string) Str::uuid(), 'other@example.com');
    $payload2 = app(DataExportPayloadBuilder::class)->build($pro2->id);
    expect($payload2['design_kit'])->toBeEmpty();
});

it('exports integrations user-scoped with payload/display_settings decoded to arrays (PRIV-3)', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $otherId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'platform' => 'instagram',
            'resource_id' => 'jane.the.stylist',
            'resource_kind' => null,
            'canonical_key' => 'instagram:jane.the.stylist',
            'payload' => json_encode(['username' => 'jane.the.stylist', 'follower_count' => 1200]),
            'display_settings' => json_encode(['show_follower_count' => true]),
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => '2026-05-01T00:00:00Z',
            'updated_at' => '2026-05-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'platform' => 'shopify',
            'resource_id' => 'jane-store',
            'resource_kind' => null,
            'canonical_key' => 'shopify:jane-store',
            'payload' => json_encode(['shop_domain' => 'jane-store.myshopify.com']),
            'display_settings' => null,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => '2026-05-02T00:00:00Z',
            'updated_at' => '2026-05-02T00:00:00Z',
        ],
        // Different user's connection — must not appear.
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'platform' => 'tiktok',
            'resource_id' => 'not-jane',
            'resource_kind' => null,
            'canonical_key' => 'tiktok:not-jane',
            'payload' => json_encode(['username' => 'not-jane']),
            'display_settings' => null,
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => '2026-05-03T00:00:00Z',
            'updated_at' => '2026-05-03T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('integrations');
    expect($payload['integrations'])->toHaveCount(2);

    $igRow = collect($payload['integrations'])->firstWhere('platform', 'instagram');
    expect($igRow['payload'])->toBeArray();
    expect($igRow['payload']['username'])->toBe('jane.the.stylist');
    expect($igRow['display_settings'])->toBeArray();
    expect($igRow['display_settings']['show_follower_count'])->toBeTrue();

    $shopifyRow = collect($payload['integrations'])->firstWhere('platform', 'shopify');
    expect($shopifyRow['display_settings'])->toBeNull();

    $platforms = collect($payload['integrations'])->pluck('platform')->sort()->values()->all();
    expect($platforms)->toBe(['instagram', 'shopify']);
});

it('excludes internal refresh machinery columns from the integrations export (PRIV-3)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'platform' => 'google-business',
        'resource_id' => 'jane-salon',
        'resource_kind' => null,
        'canonical_key' => 'google-business:jane-salon',
        'payload' => json_encode(['name' => 'Jane\'s Salon']),
        'display_settings' => null,
        'sort_order' => 0,
        'is_active' => 1,
        'last_refresh_status' => 'ok',
        'last_refresh_error' => 'timeout contacting upstream',
        'consecutive_failures' => 3,
        'apify_status' => 'SUCCEEDED',
        'place_id' => 'ChIJ_internal_place_id',
        'refresh_etag' => 'W/"abc123"',
        'refresh_last_modified' => 'Wed, 01 Jul 2026 00:00:00 GMT',
        'created_at' => '2026-05-01T00:00:00Z',
        'updated_at' => '2026-05-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $row = $payload['integrations'][0];
    expect($row)->not->toHaveKey('user_id');
    expect($row)->not->toHaveKey('last_refresh_error');
    expect($row)->not->toHaveKey('consecutive_failures');
    expect($row)->not->toHaveKey('apify_status');
    expect($row)->not->toHaveKey('place_id');
    expect($row)->not->toHaveKey('refresh_etag');
    expect($row)->not->toHaveKey('refresh_last_modified');

    // But the user-facing status is still there.
    expect($row['last_refresh_status'])->toBe('ok');
});

it('exports all four analytics sections user-scoped (PRIV-4)', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $otherId = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'site_id' => (string) Str::uuid(),
            'occurred_at' => '2026-06-01T00:00:00Z',
            'referrer' => 'https://google.com',
            'country_code' => 'AU',
            'device_type' => 'mobile',
            'created_at' => '2026-06-01T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'site_id' => (string) Str::uuid(),
            'occurred_at' => '2026-06-01T00:00:00Z',
            'referrer' => null,
            'country_code' => 'US',
            'device_type' => 'desktop',
            'created_at' => '2026-06-01T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'site_id' => (string) Str::uuid(),
            'occurred_at' => '2026-06-02T00:00:00Z',
            'platform' => 'instagram',
            'url' => 'https://instagram.com/jane',
            'created_at' => '2026-06-02T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'site_id' => (string) Str::uuid(),
            'occurred_at' => '2026-06-02T00:00:00Z',
            'platform' => 'tiktok',
            'url' => 'https://tiktok.com/not-jane',
            'created_at' => '2026-06-02T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('analytics.section_views')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'site_id' => (string) Str::uuid(),
            'section_key' => 'hero',
            'occurred_at' => '2026-06-03T00:00:00Z',
            'duration_ms' => 4200,
            'created_at' => '2026-06-03T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'site_id' => (string) Str::uuid(),
            'section_key' => 'hero',
            'occurred_at' => '2026-06-03T00:00:00Z',
            'duration_ms' => 100,
            'created_at' => '2026-06-03T00:00:00Z',
        ],
    ]);

    DB::connection('pgsql')->table('analytics.item_views')->insert([
        [
            'id' => (string) Str::uuid(),
            'user_id' => $pro->id,
            'site_id' => (string) Str::uuid(),
            'item_type' => 'service',
            'item_id' => 'svc-1',
            'item_title' => 'Haircut',
            'occurred_at' => '2026-06-04T00:00:00Z',
            'created_at' => '2026-06-04T00:00:00Z',
        ],
        [
            'id' => (string) Str::uuid(),
            'user_id' => $otherId,
            'site_id' => (string) Str::uuid(),
            'item_type' => 'service',
            'item_id' => 'svc-2',
            'item_title' => 'Not Jane\'s service',
            'occurred_at' => '2026-06-04T00:00:00Z',
            'created_at' => '2026-06-04T00:00:00Z',
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('analytics');

    expect($payload['analytics']['site_visits'])->toHaveCount(1);
    expect($payload['analytics']['site_visits'][0]['country_code'])->toBe('AU');

    expect($payload['analytics']['link_clicks'])->toHaveCount(1);
    expect($payload['analytics']['link_clicks'][0]['platform'])->toBe('instagram');

    expect($payload['analytics']['section_views'])->toHaveCount(1);
    expect($payload['analytics']['section_views'][0]['duration_ms'])->toBe(4200);

    expect($payload['analytics']['item_views'])->toHaveCount(1);
    expect($payload['analytics']['item_views'][0]['item_title'])->toBe('Haircut');
});

it('redacts visitor fingerprints from every analytics section (PRIV-4)', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'occurred_at' => '2026-06-01T00:00:00Z',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => 'sha256-visitorip',
        'user_agent' => 'Mozilla/5.0',
        'country_code' => 'AU',
        'created_at' => '2026-06-01T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'occurred_at' => '2026-06-02T00:00:00Z',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => 'sha256-visitorip',
        'user_agent' => 'Mozilla/5.0',
        'platform' => 'instagram',
        'created_at' => '2026-06-02T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'section_key' => 'hero',
        'occurred_at' => '2026-06-03T00:00:00Z',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => 'sha256-visitorip',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => '2026-06-03T00:00:00Z',
    ]);

    DB::connection('pgsql')->table('analytics.item_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'site_id' => $siteId,
        'item_type' => 'service',
        'item_id' => 'svc-1',
        'occurred_at' => '2026-06-04T00:00:00Z',
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'ip_hash' => 'sha256-visitorip',
        'user_agent' => 'Mozilla/5.0',
        'created_at' => '2026-06-04T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    foreach (['site_visits', 'link_clicks', 'section_views', 'item_views'] as $section) {
        $row = $payload['analytics'][$section][0];
        expect($row)->not->toHaveKey('ip_hash');
        expect($row)->not->toHaveKey('visitor_id');
        expect($row)->not->toHaveKey('session_id');
        expect($row)->not->toHaveKey('user_agent');
    }

    // Business columns are still present, proving redaction didn't wipe the whole row.
    expect($payload['analytics']['site_visits'][0]['country_code'])->toBe('AU');
    expect($payload['analytics']['link_clicks'][0]['platform'])->toBe('instagram');
    expect($payload['analytics']['section_views'][0]['section_key'])->toBe('hero');
    expect($payload['analytics']['item_views'][0]['item_type'])->toBe('service');
});

it('every section stream() yields resolves to a real key in build() — FOUND-1 regression guard', function () {
    // FOUND-1: build() and stream() used to be two independently hand-written
    // enumerations of the same ~26 sections; a missed edit to one silently
    // dropped a section from a GDPR export with nothing warning anyone. Now
    // build() derives its output from stream()'s own descriptors, so this
    // assertion can only fail if someone reintroduces a second, separately
    // maintained list — it is not possible for the two to drift on their own.
    $pro = seedProForPayload((string) Str::uuid());
    $builder = app(DataExportPayloadBuilder::class);

    $streamedNames = [];
    foreach ($builder->stream($pro->id) as $section) {
        $streamedNames[] = $section['name'];
    }

    // Sanity check the manifest itself isn't accidentally empty.
    expect($streamedNames)->not->toBeEmpty();

    $payload = $builder->build($pro->id);

    foreach ($streamedNames as $name) {
        expect(Arr::has($payload, $name))
            ->toBeTrue("build() is missing section '{$name}' that stream() yields");
    }
});
