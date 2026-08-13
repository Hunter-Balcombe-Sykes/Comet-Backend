<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceWriter;
use App\Services\Migration\ServiceBackfiller;
use App\Services\User\DataExport\DataExportPayloadBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\User\DataExport\DataExportTestCase;

beforeEach(function () {
    DataExportTestCase::boot();
    setupContentTables();
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

it('#PRIV-2: withholds Google Business reviewer PII from the integrations payload but keeps the owner\'s own data', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'platform' => 'google-business',
        'resource_id' => 'jane-salon',
        'resource_kind' => null,
        'canonical_key' => 'google-business:jane-salon',
        'payload' => json_encode([
            'name' => "Jane's Salon",
            'address' => '123 Example St',
            'reviews' => [['author_name' => 'A Reviewer', 'text' => 'Great!']],
            'reviewSummary' => ['count' => 12, 'average' => 4.8],
        ]),
        'display_settings' => null,
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => '2026-05-01T00:00:00Z',
        'updated_at' => '2026-05-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $row = $payload['integrations'][0];
    expect($row['payload'])->not->toHaveKey('reviews');
    expect($row['payload'])->not->toHaveKey('reviewSummary');
    expect($row['payload']['name'])->toBe("Jane's Salon");
    expect($row['payload']['address'])->toBe('123 Example St');
});

it('#PRIV-2: withholds Eventbrite organiser/venue identity but keeps location (the account holder\'s own geography)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'platform' => 'eventbrite',
        'resource_id' => 'jane-events',
        'resource_kind' => null,
        'canonical_key' => 'eventbrite:jane-events',
        'payload' => json_encode([
            'url' => 'https://eventbrite.com/o/jane-events',
            'organiser' => ['name' => 'Jane Events Co', 'id' => 'org-123'],
            'venue' => ['name' => 'The Venue', 'address' => '9 Venue Rd'],
            'upcoming' => [['id' => 'evt-1', 'name' => 'Workshop']],
            'location' => 'Sydney, NSW',
        ]),
        'display_settings' => null,
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => '2026-05-01T00:00:00Z',
        'updated_at' => '2026-05-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $row = $payload['integrations'][0];
    expect($row['payload'])->not->toHaveKey('organiser');
    expect($row['payload'])->not->toHaveKey('venue');
    expect($row['payload']['url'])->toBe('https://eventbrite.com/o/jane-events');
    expect($row['payload']['upcoming'])->not->toBeEmpty();
    // The deliberate asymmetry with venue: location is the event's own
    // geography, published by the account holder themselves.
    expect($row['payload']['location'])->toBe('Sydney, NSW');
});

it('metadata.withheld is a non-empty transparency disclosure (Article 15)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['metadata']['withheld'])->toBeString();
    expect($payload['metadata']['withheld'])->not->toBe('');
});

it('DINT-2: exports content.* sections user-scoped, with reviewer identity withheld from f_review', function () {
    setupContentTables();

    $pro = seedProForPayload((string) Str::uuid());
    $otherId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $sourceId = (string) Str::uuid();
    $otherSourceId = (string) Str::uuid();

    DB::connection('pgsql')->table('content.sources')->insert([
        ['id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'manual', 'priority' => 100, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $otherSourceId, 'user_id' => $otherId, 'kind' => 'manual', 'priority' => 100, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $itemId = seedContentItem($pro->id, ['id' => (string) Str::uuid(), 'kind' => 'review', 'headline_cache' => 'A great review']);
    $otherItemId = seedContentItem($otherId, ['id' => (string) Str::uuid(), 'kind' => 'review', 'headline_cache' => "Not Jane's item"]);

    DB::connection('pgsql')->table('content.source_items')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'coord' => 'google-business:jane-salon:review-1',
        'item_id' => $itemId,
        'kind' => 'review',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
    ]);

    DB::connection('pgsql')->table('content.f_place')->insert([
        'item_id' => $itemId,
        'source_id' => $sourceId,
        'venue_name' => "Jane's Salon",
        'address' => '123 Example St',
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('content.f_review')->insert([
        'item_id' => $itemId,
        'source_id' => $sourceId,
        'author_name' => 'A Reviewer',
        'author_photo_url' => 'https://example.com/photo.jpg',
        'rating' => 5,
        'text' => 'Great service!',
        'updated_at' => $now,
    ]);

    // Another user's facet rows — must never leak into Jane's export.
    DB::connection('pgsql')->table('content.f_place')->insert([
        'item_id' => $otherItemId,
        'source_id' => $otherSourceId,
        'venue_name' => 'Not Jane\'s Venue',
        'updated_at' => $now,
    ]);
    DB::connection('pgsql')->table('content.f_review')->insert([
        'item_id' => $otherItemId,
        'source_id' => $otherSourceId,
        'author_name' => 'Someone Else Reviewer',
        'rating' => 3,
        'updated_at' => $now,
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['content']['sources'])->toHaveCount(1);
    expect($payload['content']['items'])->toHaveCount(1);
    expect($payload['content']['items'][0]['headline_cache'])->toBe('A great review');
    expect($payload['content']['source_items'])->toHaveCount(1);

    expect($payload['content']['f_place'])->toHaveCount(1);
    expect($payload['content']['f_place'][0]['venue_name'])->toBe("Jane's Salon");

    expect($payload['content']['f_review'])->toHaveCount(1);
    $reviewRow = $payload['content']['f_review'][0];
    expect($reviewRow)->not->toHaveKey('author_name');
    expect($reviewRow)->not->toHaveKey('author_photo_url');
    expect($reviewRow)->not->toHaveKey('text');
    expect((float) $reviewRow['rating'])->toBe(5.0);

    // Cross-tenant leak check across the whole payload, not just this section.
    expect(json_encode($payload))->not->toContain('Someone Else Reviewer');
    expect(json_encode($payload))->not->toContain("Not Jane's Venue");
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

// ─── #PRIV-13: moderation evidence — the subject's own frozen identity ──────
//
// No shared helper here on purpose: seedEvidenceRow()/seedEvidencePurgeUser()
// are already taken globally by AccountDeletionPurgeEvidencePiiTest, and
// unnamespaced Pest files share ONE symbol table — a redeclaration aborts the
// whole suite rather than failing a test.

it('#PRIV-13 exports the subject\'s own frozen identity from a content_snapshot, allowlisted', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $caseId = (string) Str::uuid();
    $evidenceId = (string) Str::uuid();
    $signalId = (string) Str::uuid();

    // display_name carries a SLASH on purpose: it is the control that proves
    // the JSON_UNESCAPED_SLASHES assertions below can still go red. The
    // reporter_* keys are sentinels for payload shapes a future snapshot
    // strategy could add — the positive build must drop them without anyone
    // having remembered to exclude them.
    DB::connection('pgsql')->table('moderation.evidence')->insert([
        'id' => $evidenceId,
        'case_id' => $caseId,
        'signal_id' => $signalId,
        'evidence_type' => 'content_snapshot',
        'payload' => json_encode([
            'site_id' => (string) Str::uuid(),
            'site_subdomain' => 'jsmith',
            'user_id' => $pro->id,
            'handle' => 'jsmith',
            'display_name' => 'John Smith a/k/a JS',
            'block_count' => 3,
            'block_types' => ['gallery', 'contact'],
            'captured_at' => '2026-06-01T00:00:00+00:00',
            'reporter_handle' => 'https://third-party.example/REPORTER-SENTINEL.jpg',
            'reporter_note' => 'REPORTER-SENTINEL-NOTE',
        ], JSON_THROW_ON_ERROR),
        'content_hash' => 'CONTENT-HASH-SENTINEL',
        'captured_at' => '2026-06-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('moderation_evidence')
        ->and($payload['moderation_evidence'])->toHaveCount(1);

    $row = $payload['moderation_evidence'][0];

    // EXACT key set, not "has these keys" — a new key appearing here is a leak.
    expect(array_keys($row))->toBe([
        'id', 'case_id', 'evidence_type', 'captured_at',
        'handle', 'display_name', 'site_subdomain',
    ]);

    expect($row['id'])->toBe($evidenceId)
        ->and($row['case_id'])->toBe($caseId)
        ->and($row['evidence_type'])->toBe('content_snapshot')
        ->and($row['handle'])->toBe('jsmith')
        ->and($row['display_name'])->toBe('John Smith a/k/a JS')
        ->and($row['site_subdomain'])->toBe('jsmith');

    // JSON_UNESCAPED_SLASHES is load-bearing: plain json_encode() writes "\/",
    // so a not->toContain() on any slash-bearing needle is unfalsifiable.
    $serialised = json_encode($payload['moderation_evidence'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    // Control: a slash-bearing value that IS exported must be findable in this
    // serialisation. If this fails, every not->toContain() below is vacuous.
    expect($serialised)->toContain('a/k/a');

    // toContain() is VARIADIC — a second argument is another needle, not a
    // failure message. Never pass one.
    expect($serialised)->not->toContain('https://third-party.example/REPORTER-SENTINEL.jpg');
    expect($serialised)->not->toContain('REPORTER-SENTINEL-NOTE');
    expect($serialised)->not->toContain('CONTENT-HASH-SENTINEL');
    expect($serialised)->not->toContain($signalId);
});

it('#PRIV-13 exports no evidence row whose evidence_type is not content_snapshot', function () {
    $pro = seedProForPayload((string) Str::uuid());

    // Both non-content_snapshot types that carry real risk: csam_hash_match
    // disclosure is a law-enforcement tipping-off hazard, staff_attachment is
    // internal. Neither is the subject's own frozen identity.
    foreach (['csam_hash_match' => 'CSAM-SENTINEL', 'staff_attachment' => 'STAFF-ATTACHMENT-SENTINEL'] as $type => $sentinel) {
        DB::connection('pgsql')->table('moderation.evidence')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => (string) Str::uuid(),
            'signal_id' => null,
            'evidence_type' => $type,
            'payload' => json_encode(['user_id' => $pro->id, 'handle' => $sentinel], JSON_THROW_ON_ERROR),
            'content_hash' => null,
            'captured_at' => '2026-06-01T00:00:00Z',
        ]);
    }

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['moderation_evidence'])->toBe([]);

    // Asserted over the WHOLE export, not just the section: these rows must not
    // surface through any other section either.
    $serialised = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    expect($serialised)->not->toContain('CSAM-SENTINEL');
    expect($serialised)->not->toContain('STAFF-ATTACHMENT-SENTINEL');
});

it('#PRIV-13 exports no evidence row belonging to another user', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('moderation.evidence')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => (string) Str::uuid(),
        'signal_id' => null,
        'evidence_type' => 'content_snapshot',
        // Scoping is on payload->user_id ALONE, with no join to
        // moderation.cases — identical to purgeReportedUserEvidencePii(), so
        // export and erasure provably cover the same row set.
        'payload' => json_encode([
            'user_id' => (string) Str::uuid(),
            'handle' => 'OTHER-USER-SENTINEL',
            'display_name' => 'Someone Else',
            'site_subdomain' => 'someone-else',
        ], JSON_THROW_ON_ERROR),
        'content_hash' => null,
        'captured_at' => '2026-06-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload['moderation_evidence'])->toBe([]);
    expect(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        ->not->toContain('OTHER-USER-SENTINEL');
});

// Slice 3a Task 6: owner-authored services moved to content.* (Task 4/5), but
// the DSAR export kept streaming site.services — which ManualServiceWriter
// never writes back to once a row is in the manual lane. From cutover
// onward that made the export silently disclose PRE-EDIT values. These
// tests cover: a brand-new service (never had a site.services row at all),
// a post-cutover edit to an already-backfilled one (site.services keeps the
// OLD values forever), legacy-id continuity for a backfilled-but-unedited
// row, and the dedup trap in recovering that legacy id (content.source_items
// is unique on (source_id, coord), not item_id — a merged item can carry two
// manual coords, and a naive coord lookup would export it twice).
//
// ManualServiceWriter/ProjectionWriter issue raw DB::table() calls with no
// explicit connection (unlike DataExportPayloadBuilder itself, which always
// scopes to 'pgsql') — they resolve against config('database.default').
// DataExportTestCase::boot() (this file's beforeEach) deliberately points
// that at 'sqlite', a SEPARATE :memory: database from the 'pgsql' connection
// every setup helper writes to. tests/TestCase.php normally forces
// 'database.default' back to 'pgsql' for the rest of the suite; restoring
// that locally here is what lets the production write path resolve to the
// same in-memory database this test seeded.
it('exports a service created purely through the new content.* write path (never touched site.services)', function () {
    config(['database.default' => 'pgsql']);
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    // Mirrors UserServiceController::store()'s write — a fresh manual coord,
    // written and pinned through ManualServiceWriter, with no corresponding
    // site.services row ever created.
    $writer = app(ManualServiceWriter::class);
    $site = Site::query()->find($siteId);
    $coord = 'manual:'.(string) Str::uuid();
    $itemId = $writer->write($pro->id, $coord, $writer->projectionFor((object) [
        'title' => 'Brand New Service',
        'description' => 'Fresh from the new write path.',
        'price_cents' => 4500,
        'currency_code' => 'AUD',
        'duration_minutes' => 30,
    ]));
    $writer->pin($site, $itemId, 1.0);

    expect(DB::connection('pgsql')->table('site.services')->where('user_id', $pro->id)->count())->toBe(0);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    // Section keys unchanged — the 2026-08-05 precedent keeps legacy DSAR
    // keys so a previously-stored export payload stays disclosable.
    expect($payload)->toHaveKey('services');
    expect($payload)->toHaveKey('service_categories');

    $row = collect($payload['services'])->firstWhere('id', $itemId);
    expect($row)->not->toBeNull();
    expect($row['title'])->toBe('Brand New Service');
    expect($row['description'])->toBe('Fresh from the new write path.');
    expect($row['price_cents'])->toBe(4500);
    expect($row['currency_code'])->toBe('AUD');
    expect($row['duration_minutes'])->toBe(30);
    expect($row['is_active'])->toBeTrue();
    expect($row['source'])->toBeNull();
    expect($row['is_manual'])->toBeFalse();
    expect($row['category_ids'])->toBe([]);
});

it('reflects a post-cutover edit to an already-backfilled owner service, not the stale site.services values', function () {
    config(['database.default' => 'pgsql']);
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    // Pre-cutover state: a legacy row, already migrated to content.* by the
    // one-off backfill (tests/Pest.php's ownerService() + ServiceBackfiller —
    // exactly how production got there, per the task brief).
    $serviceId = ownerService($pro->id, [
        'title' => 'Old Title',
        'description' => 'Old description.',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
    ]);
    app(ServiceBackfiller::class)->run();

    // The cutover edit: UserServiceController::update() writes content.*
    // ONLY for a manual item (never back to site.services) — reproduced here
    // with the same coord the backfill used and the same production writer
    // the controller calls.
    $writer = app(ManualServiceWriter::class);
    $writer->write($pro->id, 'manual:'.$serviceId, $writer->projectionFor((object) [
        'title' => 'New Title',
        'description' => 'New description.',
        'price_cents' => 9900,
        'currency_code' => 'AUD',
        'duration_minutes' => null,
    ]));

    // The legacy row is untouched — it is the stale value the pre-Task-6
    // export would have disclosed.
    expect(DB::connection('pgsql')->table('site.services')->where('id', $serviceId)->value('title'))->toBe('Old Title');

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    expect($payload)->toHaveKey('services');

    // The exported id is the ORIGINAL site.services.id, not the content.*
    // item id — legacy-id continuity across the cutover (see
    // ManualServiceItems::exportRows()'s docblock).
    $row = collect($payload['services'])->firstWhere('id', $serviceId);
    expect($row)->not->toBeNull();
    expect($row['title'])->toBe('New Title');
    expect($row['description'])->toBe('New description.');
    expect($row['price_cents'])->toBe(9900);

    // The stale legacy value must not appear anywhere in the export.
    $titles = collect($payload['services'])->pluck('title')->all();
    expect($titles)->not->toContain('Old Title');
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Old description.');
});

it('still exports Fresha-projected services straight off site.services (unchanged by slice 3a)', function () {
    $pro = seedProForPayload((string) Str::uuid());

    DB::connection('pgsql')->table('site.services')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'title' => 'Fresha Cut',
        'price_cents' => 6000,
        'currency_code' => 'AUD',
        'is_active' => 1,
        'sort_order' => 0,
        'source' => 'fresha',
        'is_manual' => 0,
        'external_id' => 'fresha-svc-1',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $row = collect($payload['services'])->firstWhere('title', 'Fresha Cut');
    expect($row)->not->toBeNull();
    expect($row['source'])->toBe('fresha');
    expect($row['external_id'])->toBe('fresha-svc-1');
});

it('exports the ORIGINAL site.services.id for a backfilled-but-unedited owner service', function () {
    config(['database.default' => 'pgsql']);
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    $serviceId = ownerService($pro->id, ['title' => 'Consultation']);
    app(ServiceBackfiller::class)->run();

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    // Untouched since the backfill — same id a pre-cutover export would have
    // disclosed, proving the id doesn't drift just from content.* existing.
    $row = collect($payload['services'])->firstWhere('title', 'Consultation');
    expect($row)->not->toBeNull();
    expect($row['id'])->toBe($serviceId);
});

it('an item carrying two manual coords (a merge shape) exports exactly once, using the earliest coord', function () {
    $pro = seedProForPayload((string) Str::uuid());
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $pro->id,
        'subdomain' => 'jane',
        'created_at' => '2026-01-01T00:00:00Z',
    ]);

    $now = now();
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    $olderServiceId = (string) Str::uuid();
    $newerServiceId = (string) Str::uuid();

    DB::connection('pgsql')->table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'manual',
        'priority' => 100, 'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
        'headline_cache' => 'Merged Service', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => $now->toDateTimeString(), 'last_seen_at' => $now->toDateTimeString(),
        'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
    ]);

    // A candidate coord uuid is only trusted as a legacy id if a
    // site.services row for it actually exists (ManualServiceItems::
    // legacyIdsFor()'s cross-check — store()'s own coord minting has the
    // identical manual:<uuid> shape with no backing row, so shape alone
    // can't tell them apart). Two real legacy rows is also the realistic
    // shape of a merge: two previously-separate backfilled services folded
    // into one item.
    DB::connection('pgsql')->table('site.services')->insert([
        [
            'id' => $olderServiceId, 'user_id' => $pro->id, 'title' => 'Merged Service (older)',
            'price_cents' => 1000, 'currency_code' => 'AUD', 'is_active' => 1, 'sort_order' => 0,
            'created_at' => $now->copy()->subDay()->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
        ],
        [
            'id' => $newerServiceId, 'user_id' => $pro->id, 'title' => 'Merged Service (newer)',
            'price_cents' => 1000, 'currency_code' => 'AUD', 'is_active' => 1, 'sort_order' => 1,
            'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
        ],
    ]);

    // content.source_items is unique on (source_id, coord), NOT item_id — an
    // identity merge can land two manual coords on the same item. This is
    // that shape, written directly since slice 3a has no merge trigger for
    // the manual lane yet.
    DB::connection('pgsql')->table('content.source_items')->insert([
        [
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'manual:'.$olderServiceId, 'item_id' => $itemId, 'kind' => 'service',
            'first_seen_at' => $now->copy()->subDay()->toDateTimeString(), 'last_seen_at' => $now->toDateTimeString(),
        ],
        [
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'manual:'.$newerServiceId, 'item_id' => $itemId, 'kind' => 'service',
            'first_seen_at' => $now->toDateTimeString(), 'last_seen_at' => $now->toDateTimeString(),
        ],
    ]);

    $payload = app(DataExportPayloadBuilder::class)->build($pro->id);

    $rows = collect($payload['services'])->where('title', 'Merged Service')->values();
    expect($rows)->toHaveCount(1);
    // Earliest first_seen_at wins — deterministic, not the second coord's id.
    expect($rows->first()['id'])->toBe($olderServiceId);
    expect($rows->first()['id'])->not->toBe($newerServiceId);
});
