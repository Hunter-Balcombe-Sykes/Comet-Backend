<?php

namespace App\Services\User;

use App\Jobs\Account\SendAccountDeletionRequestMailJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Models\Moderation\Evidence;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\User\Concerns\ResolvesDeletedEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// V2: All account-deletion business logic. Called from
// UserAccountDeletionController for request/confirm/cancel flows, and
// from PurgeSoftDeleted command for hard-delete after grace period.
class AccountDeletionService
{
    use ResolvesDeletedEmail;

    /**
     * PII tables this service erases by an EXPLICIT keyed operation, because no
     * FK cascade reaches them — they are joined by email_lc or cleaned per-row,
     * not by a user_id FK. Read by DataExportCoverageTest: anything exported
     * must also be erasable, and these are the ones erasure has to do by hand.
     *
     * "Erases" covers two shapes, not one. Most entries are a DELETE. The two
     * `moderation.*` entries are an IN-PLACE REDACTION instead — the rows are
     * moderation evidence and deleting them would destroy the record of a
     * report we may have to defend, so the PII columns/keys are nulled or
     * tombstoned and the row survives. Erasure obligation met, evidence intact.
     *
     * Why no cascade reaches either moderation table: both links to
     * `core.users` are ON DELETE SET NULL, not CASCADE —
     * `case_signals_reporter_user_fk` (baseline_pilot.sql:3756-3757) and
     * `cases_owner_user_fk` (:3761-3762). So forceDelete() nulls the FK and
     * leaves the row. `moderation.evidence` is one hop further out: its own
     * `evidence_case_fk` IS ON DELETE CASCADE, but it cascades from
     * `moderation.cases`, and that case row is exactly what `cases_owner_user_fk`
     * preserves — so the cascade never fires for an account deletion.
     *
     * `moderation.case_signals` has THREE erasure paths, and they are NOT
     * interchangeable — each is keyed differently and two of them erase
     * different column sets:
     *   1. purgeCaseSignalPii() below — the subject's own account deletion.
     *      Nulls reporter_email / reason_details / signal_data.
     *   2. `moderation:prune-resolved-signal-pii` (weekly) — same column set,
     *      keyed on the PARENT case's resolved_at instead of a deletion event.
     *      Time-based retention, unrelated to any account.
     *   3. `moderation:redact-reporter-pii {case_id}` — staff-run, per-CASE,
     *      for a reporter's own erasure request. Deliberately different column
     *      set: it clears reporter_email AND reporter_ip_hash (which 1 and 2
     *      retain for T&S dedup) but leaves reason_details / signal_data.
     * Changing the column set in 1 means changing 2; 3 is its own contract.
     *
     * core.users is the deletion subject itself (forceDelete), which is what
     * triggers every FK cascade — so it heads the list rather than sitting in
     * the cascade category.
     *
     * Adding an email-keyed PII table? Add a purge*() call in purge() AND list
     * the table here, or the coverage guard fails.
     *
     * The whole `content.*` and `ingest.*` pipeline is absent from this list on
     * purpose — every table there is reached by an FK cascade from `core.users`:
     * `content.sources` / `content.items` / `content.identity_*` /
     * `content.item_anchors` / `content.item_merges` cascade on `user_id`; every
     * `content.f_*` facet and `content.source_items` cascades through
     * `content.items` / `content.sources`; `ingest.sources` cascades on
     * `user_id`, `ingest.streams` / `runs` on `source_id`, and
     * `ingest.record_state` / `anomalies` on `stream_id`.
     * `ingest.record_versions` was the one exception — no FK at all until
     * migration `20260729120001` added `record_versions_stream_id_fk` (DINT-1).
     * Its erasure now rides that cascade, and
     * `tests/Postgres/IngestCascadeDeletionTest.php` is what proves it, because
     * SQLite cannot.
     * `ingest.effects` is the second exception and is purged explicitly below
     * (SET NULL, not cascade).
     *
     * #PRIV-3: reviewer PII on `content.f_review` (author_name, author_photo_url,
     * text) belongs to a THIRD-PARTY reviewer, not the account holder cascading
     * through this list — so a reviewer-initiated erasure request (their own
     * APP 12/13 / GDPR Art. 15/17 request) can't ride account deletion at all.
     * `idx_f_review_author_lookup` (lower(author_name), partial WHERE NOT NULL,
     * migration `20260729160001`) is that request's lookup path: staff answer
     * "what do you hold about me" with an indexed equality scan and a manual
     * DELETE — there is deliberately no self-service endpoint pre-pilot. The
     * scheduled `content:prune-orphaned-review-pii` command (paired with this
     * docblock the same way `moderation:prune-resolved-signal-pii` pairs with
     * `purgeCaseSignalPii()` below) covers the separate live-account case: a
     * review the reviewer deleted upstream, on a connection that is still
     * connected, which no cascade here would ever reach.
     */
    public const PURGED_PII_TABLES = [
        'core.users',                          // forceDelete() — the subject row
        'core.early_access_signups',           // purgeEarlyAccessSignup() — user_id OR (email_lc + still-waitlist) keyed, mirrors the export's ownership rule
        'core.feedback',                       // purgeFeedbackRows() — FK is SET NULL, not CASCADE
        'notifications.email_subscriptions',   // purgeGlobalEmailSubscriptions() + purgeCrossTenantSubscriptions()
        'analytics.item_views',                // purgeItemViewsPii() — user_id is a denormalised column, no FK
        'analytics.action_events',             // purgeActionEventsPii() — same denormalised-column shape as item_views
        'ingest.effects',                      // purgeIngestEffects() — FK is SET NULL (money ledger), not CASCADE
        'moderation.case_signals',             // purgeCaseSignalPii() — in-place redaction; FK is SET NULL, row is evidence
        'moderation.evidence',                 // purgeReportedUserEvidencePii() — in-place payload tombstone; no FK to the user at all
    ];

    /**
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * queues the confirmation email. Token write, job dispatch, and audit log
     * commit atomically in the same transaction; afterCommit on the job class
     * defers the actual queue push until after that commit — see the in-closure
     * comment below for what that does and does not guarantee.
     *
     * The "user holds an active token IFF the confirmation email was sent"
     * invariant is preserved by SendAccountDeletionRequestMailJob::failed(),
     * which clears the token if all mail retries are exhausted.
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function request(User $professional, Request $request): array
    {
        $obligations = $this->checkObligations($professional);
        if (! empty($obligations)) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled before deletion.',
                'reasons' => $obligations,
            ];
        }

        $rawToken = Str::random(64);
        $tokenHash = hash('sha256', $rawToken);

        // Build the token-bearing confirmation link here (business logic stays in
        // the service). The consume side hash-matches the token against
        // deletion_token_hash, so the URL must carry the raw token to work — which
        // is why the job is ShouldBeEncrypted: the URL is a bearer secret and must
        // never sit in the plaintext Redis payload. The job receives the URL + the
        // non-reversible tokenHash, never the bare raw token.
        $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/account/deletion/confirm?token='.$rawToken;

        try {
            // Pin the transaction to 'pgsql' explicitly so it shares the connection
            // with the Eloquent writes inside (User extends BaseModel which forces
            // pgsql). Using bare DB::transaction() would target the default
            // connection, which is 'sqlite' in feature tests — making the wrapper
            // a no-op and breaking rollback.
            DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
                // WHK-102: idempotency guard against a double-submit race (double-click /
                // mobile double-tap with no Idempotency-Key header, or two identical keys
                // racing before either commits). Re-read under lockForUpdate; whoever wins
                // the lock mints the token, and the loser — seeing an unexpired token already
                // on the row — no-ops (no duplicate confirmation email, no duplicate audit
                // row). The 24h window mirrors confirm()'s own expiry check exactly (see the
                // `$requestedAt->lt(now()->subHours(24))` guard in confirm()), so the two
                // halves cannot drift: a token this guard treats as "still active" is never
                // one confirm() would treat as expired, and vice versa.
                $locked = User::query()->lockForUpdate()->find($professional->id);

                if ($locked
                    && $locked->deletion_token_hash !== null
                    && $locked->deletion_requested_at !== null
                    && ! Carbon::parse((string) $locked->deletion_requested_at)->lt(now()->subHours(24))
                ) {
                    // Loser of the race (or a legitimate re-tap while a live token is still
                    // valid): skip the write/dispatch/log entirely. The caller still gets the
                    // same success-shaped 200 as the winner — a double-tap must never surface
                    // an error to the user.
                    return;
                }

                // SEC-2: deletion_* columns are no longer fillable — forceFill so this
                // persisted-row write isn't a silent no-op.
                $professional->forceFill([
                    'deletion_token_hash' => $tokenHash,
                    'deletion_requested_at' => now(),
                    // Reset sent_at so the idempotency guard in the job allows re-delivery —
                    // mirrors the subscription reset-on-re-subscribe precedent in PublicEmailSubscriptionController.
                    'deletion_mail_sent_at' => null,
                ])->save();

                // TXN-102: this call only REGISTERS the dispatch — it does not push to Redis
                // here. The job sets $this->afterCommit = true, so Laravel defers the actual
                // queue push (and the sync-queue driver's immediate execution of it) until
                // AFTER this transaction commits. The token write and the audit row below
                // commit atomically with each other, but the queue push is NOT part of that
                // atomicity — it happens strictly later. A push failure at that point leaves a
                // durable, committed token that was never mailed; that state self-corrects via
                // SendAccountDeletionRequestMailJob::failed() (clears the token so the user can
                // retry immediately) and, as a backstop, the 24h expiry in confirm().
                SendAccountDeletionRequestMailJob::dispatch(
                    $professional->id,
                    $confirmationUrl,
                    $tokenHash,
                );

                $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
            });
        } catch (\Throwable $e) {
            Log::error('Account deletion request failed', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-1: surface to Nightwatch — a swallowed failure here silently
            // strands the user with no deletion request and no alert.
            report($e);

            return [
                'success' => false,
                'code' => 503,
                'error' => 'Failed to initiate deletion request. Please try again.',
            ];
        }

        return ['success' => true, 'code' => 200];
    }

    /**
     * Confirm deletion via token. Snapshots previous status, flips to
     * pending_deletion, sends scheduled mail.
     *
     * @return array{success: bool, code: int, error?: string, deletes_at?: string}
     */
    public function confirm(User $professional, string $rawToken, Request $request): array
    {
        // No deletion request on file?
        if (! $professional->deletion_token_hash || ! $professional->deletion_requested_at) {
            return ['success' => false, 'code' => 404, 'error' => 'No deletion request found.'];
        }

        // Token expired?
        $requestedAt = $professional->deletion_requested_at instanceof \DateTimeInterface
            ? Carbon::instance($professional->deletion_requested_at)
            : Carbon::parse((string) $professional->deletion_requested_at);

        if ($requestedAt->lt(now()->subHours(24))) {
            // SEC-2: deletion_* columns are no longer fillable — forceFill so this
            // persisted-row write isn't a silent no-op.
            $professional->forceFill([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ])->save();

            return ['success' => false, 'code' => 410, 'error' => 'Confirmation token has expired.'];
        }

        // Token mismatch? Timing-safe comparison.
        if (! hash_equals((string) $professional->deletion_token_hash, hash('sha256', $rawToken))) {
            return ['success' => false, 'code' => 404, 'error' => 'Invalid token.'];
        }

        $deletesAt = $this->executeConfirmation(
            $professional,
            UserDeletionAuditEntry::EVENT_CONFIRMED,
            $request,
        );

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Apply the confirmed deletion atomically: status flip, site unpublish,
     * audit row, and PII pseudonymisation all commit in a single transaction.
     * If any step fails the entire operation rolls back — no half-deleted
     * state where status=pending_deletion but live PII remains in the row.
     *
     * Ordering inside the transaction is load-bearing: logAuditEvent reads
     * $professional->primary_email to snapshot it, so it MUST run before
     * pseudonymiseAccountPii overwrites the live value. The scheduled mail
     * is queued after the transaction commits, addressed to the pre-wipe
     * email captured in $realEmail.
     */
    private function executeConfirmation(
        User $professional,
        string $event,
        ?Request $request,
        array $metadata = [],
        string $actorType = UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
        ?string $actorId = null,
        ?string $actorHandle = null,
        ?string $reason = null,
        bool $suppressMail = false,
    ): Carbon {
        $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
        $deletesAt = now()->addDays($retentionDays);
        $previousStatus = (string) ($professional->status ?? 'active');

        // Snapshot the real email before the transaction so the queued mail can
        // still address the user — pseudonymiseAccountPii overwrites primary_email
        // (both DB row and in-memory model) inside the transaction below.
        $realEmail = (string) ($professional->primary_email ?? '');

        $alreadyConfirmed = false;

        // Pin to 'pgsql' so the wrapper covers the same connection as the
        // Eloquent writes inside — see request() for the test-config rationale.
        DB::connection('pgsql')->transaction(function () use (
            $professional,
            $previousStatus,
            $event,
            $request,
            $metadata,
            $actorType,
            $actorId,
            $actorHandle,
            $reason,
            $retentionDays,
            &$alreadyConfirmed,
            &$deletesAt,
        ) {
            // Idempotency guard against a double-fire confirmation race (double-clicked
            // link / email-client prefetch): both requests validate the token outside any
            // lock, then race here. Re-read under lockForUpdate; whoever wins the lock flips
            // status to pending_deletion, and the loser — seeing it already set — no-ops (no
            // duplicate scheduled-deletion mail, no duplicate audit row). Keyed on status,
            // not deletion_token_hash, so the admin-initiated path (no token) is guarded too.
            $locked = User::query()->lockForUpdate()->find($professional->id);
            if ($locked && $locked->status === 'pending_deletion') {
                $alreadyConfirmed = true;
                // Reuse the winner's schedule so the caller still gets a coherent deletes_at.
                if ($locked->deletion_confirmed_at) {
                    $confirmedAt = $locked->deletion_confirmed_at instanceof \DateTimeInterface
                        ? Carbon::instance($locked->deletion_confirmed_at)
                        : Carbon::parse((string) $locked->deletion_confirmed_at);
                    $deletesAt = $confirmedAt->addDays($retentionDays);
                }

                return;
            }

            // SEC-2: status/deletion_* columns are no longer fillable — forceFill so
            // this persisted-row write isn't a silent no-op.
            $professional->forceFill([
                'deletion_previous_status' => $previousStatus,
                'status' => 'pending_deletion',
                'deletion_confirmed_at' => now(),
                'deletion_token_hash' => null,
            ])->save();

            // Immediately take the public storefront offline so a deleted brand's
            // shop stops serving requests for the full 30-day grace period.
            // SiteObserver::saved() handles cache invalidation automatically.
            if ($professional->site) {
                // unpublished_at is not mass-assignable (#SEC-18) — assign explicitly.
                $site = $professional->site;
                $site->is_published = false;
                $site->unpublished_at = now();
                $site->save();
            }

            // logAuditEvent reads $professional->primary_email to snapshot it; must
            // run BEFORE pseudonymiseAccountPii overwrites the live value. The two
            // calls commit together — if pseudonymise throws, the audit row is also
            // rolled back, so we never persist a "confirmed deletion" audit for a
            // user whose PII is still live.
            $this->logAuditEvent($professional, $event, $request, $metadata, $actorType, $actorId, $actorHandle, $reason);
            $this->pseudonymiseAccountPii($professional);
        });

        // Loser of the race: the winner already purged the cache, queued the mail, and
        // wrote the audit row. Return the shared schedule without repeating any of it.
        if ($alreadyConfirmed) {
            return $deletesAt;
        }

        // PRIV-1: flush cached idempotency responses NOW, not at the day-30 purge().
        // PII is already pseudonymised above and the account goes read-only immediately
        // after confirm (no new entries can be written post-confirm), so by the time
        // purge() runs 30 days later every 24h-TTL entry has long since expired on its
        // own — that hook would be a no-op. Runs after the transaction has committed,
        // so even if the Redis flush itself fails, the DB is already redacted.
        $this->purgeIdempotencyCache((string) ($professional->auth_user_id ?? ''));

        $cancelUrl = rtrim((string) config('app.frontend_url'), '/').'/account/deletion/cancel';

        if (! $suppressMail) {
            try {
                Mail::to($realEmail)->queue(
                    new AccountDeletionScheduledMail(
                        displayName: (string) ($professional->display_name ?? 'there'),
                        // Legally consequential date — always name the zone
                        // (no per-user timezone exists to render into).
                        deletesAt: $deletesAt->toDayDateTimeString().' (UTC)',
                        cancelUrl: $cancelUrl,
                    )
                );
            } catch (\Throwable $e) {
                Log::error('Account deletion scheduled mail dispatch failed', [
                    'user_id' => $professional->id,
                    'error' => $e->getMessage(),
                ]);
                // Do not fail the confirmation — the deletion is more important
                // than the mail. Cancel flow remains available via logged-in session.
            }
        }

        return $deletesAt;
    }

    /**
     * One-way pseudonymisation of live PII columns on core.professionals.
     *
     * The 30-day grace period only needs handle, display_name, and auth_user_id to
     * keep the "undo deletion" recovery path working; the original email is preserved
     * in audit.user_deletion_audit.professional_email_snapshot so support can
     * re-identify the user if they email to cancel.
     *
     * PRIV-102: admin_notes (staff-only freetext) is nulled here, not snapshotted.
     * Unlike primary_email, it is deliberately NOT preserved anywhere else — copying
     * staff freetext into audit.user_deletion_audit.metadata would convert a 30-day
     * exposure into permanent, structurally unerasable PII, because that audit table
     * grants app_backend SELECT/INSERT only (no UPDATE/DELETE) and its FK is
     * ON DELETE SET NULL, so its rows deliberately outlive the day-30 forceDelete
     * forever. Accepted tradeoff: if a user cancels during the grace period, any
     * admin_notes content is unrecoverable — deliberate, not a bug.
     */
    protected function pseudonymiseAccountPii(User $professional): void
    {
        $professional->forceFill([
            'phone' => 'redacted',
            'primary_email' => "deleted+{$professional->id}@partna.au",
            'first_name' => 'Deleted',
            'last_name' => null,
            'public_contact_email' => null,
            'public_contact_number' => null,
            'location_street_address' => null,
            'location_postcode' => null,
            'location_city' => null,
            'location_state' => null,
            'location_country' => null,
            'admin_notes' => null,
        ])->save();
    }

    /**
     * PRIV-1 (GDPR): flush every idempotency response-cache entry for this user
     * via the per-user Redis index that App\Http\Middleware\IdempotencyKey
     * maintains at cache-write time.
     *
     * Keys on auth_user_id (the Supabase UID) — NOT $professional->id — because
     * that's the same value the middleware uses to build its cache/index keys
     * (it reads $request->attributes->get('supabase_uid')).
     *
     * Best-effort: a Redis outage here must not abort account deletion — the DB
     * pseudonymisation this runs after is what GDPR erasure actually depends on.
     */
    private function purgeIdempotencyCache(string $authUserId): void
    {
        if ($authUserId === '') {
            return;
        }

        try {
            $connection = Redis::connection('cache');
            $indexKey = CacheKeyGenerator::idempotencyIndexKey($authUserId);

            $cacheKeys = $connection->smembers($indexKey);
            foreach ($cacheKeys as $cacheKey) {
                if (is_string($cacheKey) && $cacheKey !== '') {
                    Cache::forget($cacheKey);
                }
            }

            $connection->del($indexKey);
        } catch (\Throwable $e) {
            Log::warning('Idempotency cache purge failed during account deletion confirm', [
                'auth_user_id' => $authUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin-initiated deletion. Skips the email-token confirm step and goes
     * straight to scheduling the 30-day grace period. Used when a professional
     * emails support requesting erasure (e.g., GDPR Article 17 request).
     *
     * @param  User  $professional  The user being deleted.
     * @param  string  $staffActorId  PartnaStaff.id of the admin invoking this.
     * @param  string  $staffActorHandle  Snapshot of staff name (or email) for audit.
     * @param  string  $reason  GDPR reason / support ticket reference (10–500 chars).
     * @param  bool  $overrideObligations  If true, proceed despite unpaid balance / pending payouts.
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>, deletes_at?: string}
     */
    public function adminInitiate(
        User $professional,
        string $staffActorId,
        string $staffActorHandle,
        string $reason,
        bool $overrideObligations,
        Request $request,
    ): array {
        if ($professional->status === 'pending_deletion') {
            return ['success' => false, 'code' => 409, 'error' => 'Deletion already in progress.'];
        }

        $obligations = $this->checkObligations($professional);

        if (! empty($obligations) && ! $overrideObligations) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled or explicitly overridden.',
                'reasons' => $obligations,
            ];
        }

        $metadata = ! empty($obligations)
            ? ['obligations_overridden' => $obligations]
            : [];

        $deletesAt = $this->executeConfirmation(
            $professional,
            UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            $request,
            $metadata,
            UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Staff immediate hard-delete: run the confirmation writes (WITHOUT the
     * grace-period email) then purge() right away — deleting the Supabase auth
     * user so the email frees up. Skips the 30-day window; reuses the same
     * hardened machinery as the daily purge. Idempotent for an account already
     * in the grace period (skips the confirmation writes and purges).
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function adminPurgeNow(
        User $professional,
        string $staffActorId,
        string $staffActorHandle,
        string $reason,
        bool $overrideObligations,
        Request $request,
    ): array {
        $obligations = $this->checkObligations($professional);

        if (! empty($obligations) && ! $overrideObligations) {
            return [
                'success' => false,
                'code' => 422,
                'error' => 'Outstanding obligations must be settled or explicitly overridden.',
                'reasons' => $obligations,
            ];
        }

        // A live account needs the confirmation writes (pseudonymise PII + write the
        // ADMIN_INITIATED audit snapshot that purge() reads for email-keyed erasure).
        // An account already pending_deletion already has them — go straight to purge.
        if ($professional->status !== 'pending_deletion') {
            $metadata = ! empty($obligations) ? ['obligations_overridden' => $obligations] : [];

            $this->executeConfirmation(
                $professional,
                UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
                $request,
                $metadata,
                UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
                $staffActorId,
                $staffActorHandle,
                $reason,
                suppressMail: true,
            );
        }

        if (! $this->purge($professional)) {
            return [
                'success' => false,
                'code' => 502,
                'error' => 'Auth deletion failed; the account is marked for deletion and will be retried automatically.',
            ];
        }

        return ['success' => true, 'code' => 200];
    }

    /**
     * Admin-initiated cancel during grace period. Same lifecycle as self-service
     * cancel() but the audit row records which staff member triggered the cancel.
     *
     * @return array{success: bool, code: int, error?: string}
     */
    public function adminCancel(
        User $professional,
        string $staffActorId,
        string $staffActorHandle,
        ?string $reason,
        Request $request,
    ): array {
        if ($professional->status !== 'pending_deletion') {
            return ['success' => false, 'code' => 409, 'error' => 'No pending deletion to cancel.'];
        }

        $previousStatus = $professional->deletion_previous_status;
        if (! is_string($previousStatus) || $previousStatus === '') {
            $previousStatus = 'active';
        }

        $this->restoreSiteAndStatus($professional, $previousStatus);
        $this->sendDeletionCancelledMail($professional);

        $this->logAuditEvent(
            $professional,
            UserDeletionAuditEntry::EVENT_ADMIN_CANCELLED,
            $request,
            [],
            UserDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );

        return ['success' => true, 'code' => 200];
    }

    /**
     * Cancel a pending deletion during the grace period. Restores previous
     * status, clears deletion timestamps, sends cancellation mail.
     *
     * @return array{success: bool, code: int}
     */
    public function cancel(User $professional, Request $request): array
    {
        $previousStatus = $professional->deletion_previous_status;
        if (! is_string($previousStatus) || $previousStatus === '') {
            $previousStatus = 'active';
        }

        $this->restoreSiteAndStatus($professional, $previousStatus);
        $this->sendDeletionCancelledMail($professional);

        $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_CANCELLED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Restore status + re-publish site inside a single pgsql transaction.
     *
     * Email restore runs FIRST inside the transaction so that a rollback (e.g.
     * lockForUpdate conflict) doesn't leave primary_email restored while the
     * status update is still pending_deletion — that torn state would let a
     * cancelled-but-still-scheduled account receive email as if it were active.
     *
     * Pinned to 'pgsql' (not bare DB::transaction) so the wrapper covers the
     * same connection as the Eloquent writes inside — SQLite test DB otherwise
     * makes the wrapper a no-op. See request() for the full rationale.
     */
    private function restoreSiteAndStatus(User $professional, string $previousStatus): void
    {
        DB::connection('pgsql')->transaction(function () use ($professional, $previousStatus) {
            // Restore pseudonymised email inside the txn — if the status update rolls
            // back, the email restore rolls back too; no torn state where the real
            // email is live but the account is still pending_deletion.
            $this->restoreEmailFromAuditSnapshot($professional);

            // SEC-2: status/deletion_* columns are no longer fillable — forceFill so
            // this persisted-row write isn't a silent no-op.
            $professional->forceFill([
                'status' => $previousStatus,
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_previous_status' => null,
                'deletion_token_hash' => null,
            ])->save();

            // Re-publish the site only if it was programmatically unpublished by our
            // deletion flow (unpublished_at is the signal). A manually unpublished
            // site (unpublished_at = null) must stay offline — we don't own that state.
            // Re-read with a lock to avoid acting on a stale relation-cache snapshot —
            // a concurrent manual-unpublish could otherwise flip unpublished_at to null
            // between relation load and this check.
            $site = Site::query()
                ->where('user_id', $professional->id)
                ->lockForUpdate()
                ->first();
            if ($site && $site->unpublished_at !== null) {
                // unpublished_at is not mass-assignable (#SEC-18) — assign explicitly.
                $site->is_published = true;
                $site->unpublished_at = null;
                $site->save();
            }
        });
    }

    /**
     * Send the account-deletion-cancelled transactional mail.
     * Called OUTSIDE any transaction — mail dispatch must not hold a DB lock.
     */
    private function sendDeletionCancelledMail(User $professional): void
    {
        try {
            Mail::to($professional->primary_email)->queue(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hard-delete a professional whose grace period has elapsed. Called by
     * PurgeSoftDeleted command. Returns false on any failure so the caller
     * can retry on the next daily run.
     */
    public function purge(User $professional): bool
    {
        $handleSnapshot = (string) ($professional->handle ?? '');
        $emailSnapshot = (string) ($professional->primary_email ?? '');
        $authUserId = (string) ($professional->auth_user_id ?? '');

        // Step 1: delete Supabase auth user. If this fails, do NOT hard-delete
        // the DB row — we'd end up with an orphaned auth user and no way to retry.
        //
        // UNLESS that auth user is also a staff member. The auth user is shared:
        // core.partna_staff.auth_user_id and core.users.auth_user_id can point at
        // the same GoTrue identity, and deleting it here would revoke the staff
        // login as a side effect of erasing a professional profile — locking the
        // staff row out of a session it can never get back, since nothing in the
        // app can recreate a GoTrue identity. This is not hypothetical: retiring
        // the platform admin's unwanted profile (2026-09-01) runs exactly this
        // path against the auth user that holds his staff row.
        //
        // The purge is otherwise unchanged — every row, every artifact, every
        // email-keyed erasure still runs. What survives is one login with no
        // professional profile behind it, which is precisely what a staff account
        // is now defined to be. The email does NOT free up for re-registration,
        // and that is correct: it is still in use, as staff.
        $preserveAuthUserForStaff = $authUserId !== '' && $this->authUserHoldsStaffRow($authUserId);

        if ($preserveAuthUserForStaff) {
            Log::info('Account purge preserving the Supabase auth user: it is a staff login', [
                'user_id' => (string) $professional->id,
                'auth_user_id' => $authUserId,
            ]);
        }

        if ($authUserId !== '' && ! $preserveAuthUserForStaff && ! $this->deleteSupabaseAuthUser($authUserId)) {
            $this->logAuditEvent(
                $professional,
                UserDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'supabase_deletion_failed'],
                UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            // report() surfaces to Nightwatch while return false preserves batch-safe continue.
            report(new \RuntimeException("AccountDeletionService::purge() — Supabase auth deletion failed for user {$professional->id}"));

            return false;
        }

        // Step 2: clean up R2 artifacts before the DB cascade deletes the rows.
        // forceDelete() cascades to site_media, but DB cascades do not touch R2 storage.
        // Capture the video paths for the audit ledger (P1-08) — they outlive the
        // hard-deleted rows and seed the gdpr sweep that recovers any orphans left
        // by a DeleteMediaArtifactsJob that exhausted its retries during an R2 outage.
        $videoArtifactPaths = $this->purgeMediaArtifacts($professional);

        // Step 3: bust the public site cache (15-min TTL) so a just-purged site
        // stops serving stale payloads to public requests the instant we delete.
        // invalidateSite() handles the main subdomain + all aliases in one call.
        // Deliberate direct call (NOT redundant): teardown runs a force-delete cascade
        // that we don't want to depend on observer ordering for — invalidate explicitly.
        $site = Site::query()->where('user_id', $professional->id)->first();
        if ($site) {
            try {
                app(SiteCacheService::class)->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed during account purge', [
                    'user_id' => $professional->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // EDGE-1: capture the active custom domain before forceDelete cascades the
        // site row away. Afterwards the KV sync dispatched by UserObserver::deleted
        // can't resolve $pro->site to clear the domain:<host> pointer, so it would
        // keep routing to the purged page (and could mis-route to a new owner once
        // the freed handle is reclaimed). Retired explicitly via the job's idempotent
        // $retireCustomDomain param after the delete succeeds.
        $retireCustomDomain = ($site && ($site->custom_domain_status ?? null) === 'active')
            ? $site->custom_domain
            : null;

        // Step 3b: resolve pre-pseudonymisation email for email-keyed erasure.
        // executeConfirmation() pseudonymises primary_email before purge runs —
        // the original is preserved in the deletion audit snapshot.
        $lookupEmail = $this->resolveDeletedAccountEmail($professional);

        // Step 3c–3g: erase PII from surfaces the DB cascade won't reach.
        // Each step is independently fault-tolerant — a failure must not block forceDelete.
        $this->purgeExportZips($professional);           // #P2-08: R2 export ZIPs
        $this->purgeEarlyAccessSignup($professional, $lookupEmail);     // #P2-09: early access signup row
        $this->purgeFeedbackRows($professional);         // #P2-10: feedback (FK is SET NULL, not CASCADE)
        $this->purgeCaseSignalPii($professional, $lookupEmail); // #P2-11 + PRIV-3: reporter PII on moderation signals (email leg is the only one production populates)
        $this->purgeReportedUserEvidencePii($professional); // PRIV-4: reported-user PII in evidence payload
        $this->purgeGlobalEmailSubscriptions($professional, $lookupEmail);    // #P2-12: global (user_id IS NULL) subscriptions
        $this->purgeCrossTenantSubscriptions($professional, $lookupEmail); // PRIV-7 Gap 1: other-user-owned rows matching this email
        $this->purgeItemViewsPii($professional);          // PRIV-3: analytics.item_views has no FK to core.users
        $this->purgeActionEventsPii($professional);       // same PRIV-3 shape: analytics.action_events has no FK to core.users
        $this->purgeIngestEffects($professional);         // #PRIV-1: ingest.effects.meta carries scraped third-party PII; FK is SET NULL, not CASCADE

        // Pre-null the two append-only audit links (staff_audit_log, handle_change_log) so
        // forceDelete's ON DELETE SET NULL cascade matches 0 rows there and never trips
        // their reject-mutation triggers. Postgres-only: the helper/triggers don't exist
        // under the SQLite test driver (the 'pgsql' connection is aliased to SQLite in
        // tests, so getDriverName() returns 'sqlite' and this is skipped).
        //
        // Wrapped like the forceDelete block below: this is the only teardown step that
        // could throw (e.g. the helper not yet applied to this DB), and a throw here would
        // abort the nightly purge batch or surface as an uncaught 500 on /force — after the
        // Step-1 auth-delete already ran. Log EVENT_PURGE_FAILED, report, and return false so
        // the caller maps it to a retryable 502 and the daily command continues to the next
        // row (the retry's auth-delete sees a 404 and proceeds once the helper exists).
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            try {
                DB::connection('pgsql')->select('SELECT audit.null_user_audit_links(?)', [$professional->id]);
            } catch (\Throwable $e) {
                Log::error('null_user_audit_links failed during purge', [
                    'user_id' => $professional->id,
                    'error' => $e->getMessage(),
                ]);
                $this->logAuditEvent(
                    $professional,
                    UserDeletionAuditEntry::EVENT_PURGE_FAILED,
                    null,
                    ['reason' => 'null_audit_links_failed', 'error' => $e->getMessage()],
                    UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
                );

                report($e);

                return false;
            }
        }

        // Step 4: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
        // 3 previously-RESTRICT FKs now SET NULL). forceDelete triggers model events.
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            $this->logAuditEvent(
                $professional,
                UserDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
                UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            // report() surfaces to Nightwatch while return false preserves batch-safe continue.
            report($e);

            return false;
        }

        // EDGE-1: retire the now-orphaned custom-domain KV pointer (the handle key
        // is retired by UserObserver::deleted). Fires only when an active custom
        // domain existed at capture time.
        if ($retireCustomDomain) {
            SyncSubdomainToKvJob::dispatch((string) $professional->id, $handleSnapshot, $retireCustomDomain);
        }

        // EDGE-1 (2026-07-29): purge the edge cache for the freed handle after
        // forceDelete succeeds — not before. A pre-delete purge would be re-warmed
        // by any visitor while the KV entry (retired by UserObserver::deleted, which
        // fires from forceDelete's model event) is still live; dispatching here, in
        // the same post-delete block as the $retireCustomDomain KV retire above, puts
        // this dispatch AFTER the KV-retire dispatch in Redis FIFO order.
        //
        // That does NOT guarantee completion order and this comment previously
        // claimed it did. config/horizon.php's supervisor-1 (owns the `cloudflare`
        // queue, balance=>false) runs maxProcesses=>2 in BOTH production and
        // development — with two workers, dequeue order is not completion order:
        // worker A can take the KV-retire job while worker B takes this purge, and
        // the purge can finish before the KV entry is actually retired. A visitor
        // request landing in that narrow window is served the still-live route and
        // re-warms the very cache entry this purge just cleared.
        //
        // What actually closes that window: CloudflareCachePurgeJob::handle() always
        // schedules its own follow-up purges (partna.cache.purge_followup_schedule,
        // default [120, 300, 900] seconds) for any non-follow-up dispatch — this call
        // included, unconditionally, regardless of $bulk or moderationCaseId. Each
        // follow-up is dispatched up-front (not chained) with its own uniqueId
        // discriminator (|fu1/|fu2/|fu3), so it can't be coalesced away by this
        // purge, by a reclaimer's own SiteObserver::saved() purge (different
        // uniqueId — no `|fu` suffix), or by another follow-up depth. So even if the
        // KV-retire/purge race is lost and the route gets re-warmed, the 120s
        // follow-up evicts it — narrowing the exposure window from "until the next
        // unrelated write" to "up to ~120s", not eliminating it entirely. Accepted
        // residual: if Cloudflare's API is degraded long enough for all three
        // follow-ups (120s/300s/900s) to also fail, nothing further evicts a
        // re-warmed entry on this path — the same residual every purge on this job
        // already carries (see failed()); this dispatch adds no new exposure beyond
        // that. Job-level follow-up mechanics are pinned by
        // tests/Unit/Jobs/CloudflareCachePurgeJobTest.php.
        //
        // Unconditional (not gated on $retireCustomDomain): a hard delete has
        // NO reclaim cooldown (unlike a rename's 14d/90d alias window), so a
        // reclaimer's own SiteObserver::saved() purge races the KV write and must
        // not be the sole defence against serving the previous owner's cached HTML.
        // $retireCustomDomain is passed explicitly because it's captured pre-cascade
        // — the job's own DB fallback lookup resolves nothing once site.sites is gone.
        if ($handleSnapshot !== '') {
            try {
                CloudflareCachePurgeJob::dispatch($handleSnapshot, $retireCustomDomain);
            } catch (\Throwable $e) {
                Log::warning('Edge cache purge dispatch failed during account purge', [
                    'user_id' => $professional->id,
                    'handle' => $handleSnapshot,
                    'error' => $e->getMessage(),
                ]);
                report($e);
            }
        }

        // Direct create (not logAuditEvent) — the professional row was just
        // force-deleted, so user_id must be NULL to satisfy the FK. The handle
        // snapshot is the top-of-purge value (handles are never pseudonymised);
        // the email uses the audit-resolved pre-pseudonymisation address so this
        // PURGED receipt records the real address, not the "deleted+{id}@" placeholder
        // that primary_email already holds by this point (SEM-1).
        // SEC-3: forceCreate — user_id/professional_email_snapshot are no longer
        // fillable (server-managed on this append-only audit table).
        UserDeletionAuditEntry::forceCreate([
            'user_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $lookupEmail ?? $emailSnapshot,
            'event' => UserDeletionAuditEntry::EVENT_PURGED,
            'actor_type' => UserDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            // Ledger of R2 video paths for the orphan sweep (P1-08). Null when
            // the account had no videos, so the metadata column stays clean.
            'metadata' => $videoArtifactPaths !== []
                ? ['video_artifact_paths' => $videoArtifactPaths]
                : null,
        ]);

        return true;
    }

    /**
     * #P2-08: Delete GDPR export ZIPs from R2.
     *
     * audit.data_export_audit.user_id is SET NULL after forceDelete, so this
     * must run before the hard delete. Failures are logged and skipped —
     * a storage outage must not block account erasure.
     */
    private function purgeExportZips(User $professional): void
    {
        try {
            $disk = Storage::disk((string) config('partna.media_disk'));

            $paths = DB::connection('pgsql')
                ->table('audit.data_export_audit')
                ->where('user_id', $professional->id)
                ->whereNotNull('file_path')
                ->pluck('file_path');

            foreach ($paths as $path) {
                try {
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Export ZIP deletion failed during account purge', [
                        'user_id' => $professional->id,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Catch-all: remove the whole exports/{id}/ prefix to sweep up any ZIP
            // not captured by an audit row — e.g. one orphaned by a crash between
            // the R2 upload and the file_path DB write (the window #P2-13 guards).
            $disk->deleteDirectory("exports/{$professional->id}");
        } catch (\Throwable $e) {
            Log::error('Export ZIP erasure step failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * #P2-09 / #PRIV-1: Delete core.early_access_signups rows this account can
     * prove it owns.
     *
     * Mirrors DataExportPayloadBuilder::streamEarlyAccessSignups()'s ownership
     * predicate exactly — a row owned by user_id, or matched by email while
     * still on the waitlist, is deleted. A row matched by email only, but
     * already progressed past the waitlist, is left alone: email_lc alone
     * can't prove the row is this account's rather than a prior holder of a
     * reassigned address, and the export already refuses to disclose that row
     * in full for the same reason — deleting what we won't even show would be
     * incoherent, and would destroy a third party's record on a false
     * positive. That surviving row still ages out on its own timeline via
     * `early-access:prune-old-signups` (PruneEarlyAccessSignupsCommand),
     * which hard-deletes any non-`signed_up` row past
     * `config('partna.early_access.retention_days')` regardless of ownership.
     *
     * One gap that follows from the two filters combined: an UNOWNED
     * `signed_up` row matched only by email is skipped here (it's past the
     * waitlist) and also skipped by the prune (which excludes `signed_up`),
     * so it has no automatic purge path at all. Unreachable today —
     * EarlyAccessService::markSignedUp() has no production caller and
     * `user_id` isn't fillable — but if either changes, that row needs a
     * deliberate retention answer rather than inheriting this silence.
     */
    private function purgeEarlyAccessSignup(User $professional, ?string $lookupEmail): void
    {
        $emailLc = ($lookupEmail !== null && trim($lookupEmail) !== '')
            ? mb_strtolower(trim($lookupEmail))
            : null;

        try {
            DB::connection('pgsql')
                ->table('core.early_access_signups')
                ->where(function ($q) use ($professional, $emailLc) {
                    $q->where('user_id', $professional->id);
                    if ($emailLc !== null) {
                        $q->orWhere(fn ($q2) => $q2->whereNull('user_id')
                            ->where('email_lc', $emailLc)
                            ->where('status', 'waitlist'));
                    }
                })
                ->delete();
        } catch (\Throwable $e) {
            // LIFE-102: the failure log must carry the acting user_id — without it,
            // a purge failure here is unattributable in the log stream.
            Log::error('Early access signup erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * #P2-10: Force-delete feedback rows (bypass soft-delete).
     *
     * The FK is ON DELETE SET NULL — rows survive forceDelete with PII intact
     * (message, reply_email). Explicit deletion required for full erasure.
     */
    private function purgeFeedbackRows(User $professional): void
    {
        try {
            DB::connection('pgsql')
                ->table('core.feedback')
                ->where('user_id', $professional->id)
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Feedback erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * #P2-11: Null out reporter PII on moderation.case_signals.
     *
     * The signal itself is moderation evidence and must not be deleted.
     * reporter_ip_hash is a one-way hash and not personally identifiable — kept.
     * reason_details is up to 4000 chars of freetext that may identify the reporter — erased.
     * signal_data is the original report payload (e.g. {"details": "..."}), which carries
     * the reporter's verbatim words — reset to an empty object. The non-PII columns
     * (reason_code, signal_source, dedup_hash, case_id) are retained for T&S analytics.
     *
     * PRIV-3: the reporter_user_id leg alone erased NOTHING in production.
     * ContentReportService::submit() is the only CaseSignal writer and its
     * forceCreate() array has no reporter_user_id key (the /v1/public/report route is
     * unauthenticated — there is no principal to attribute), so every real row has
     * reporter_user_id IS NULL and the keyed predicate matched zero rows, always.
     * reporter_email is the only column that links a signal back to a person, so it is
     * the leg that has to carry the erasure. $lookupEmail MUST be the
     * pre-pseudonymisation email from resolveDeletedAccountEmail() — by the time purge()
     * runs, primary_email is already "deleted+{id}@partna.au". The reporter_user_id leg
     * is kept for any future authenticated report path.
     *
     * The two legs are wrapped in a grouped closure so the orWhere cannot escape into
     * the UPDATE's top-level WHERE and widen it to every row in the table. The
     * null/empty-$lookupEmail guard is the same one purgeGlobalEmailSubscriptions makes,
     * applied to the leg rather than the whole method (an early return here would also
     * skip the reporter_user_id leg): the orWhereRaw is omitted entirely, so an empty
     * match can never degrade into a match-everything.
     *
     * lower(trim(...)) mirrors DataExportPayloadBuilder::streamContentReports()'s SEM-1
     * fold exactly, so erasure reaches precisely the legacy mixed-case rows the export
     * discloses. On Postgres the expression is not sargable against the partial index
     * `case_signals_reporter_user_idx`, so this is a sequential scan — accepted at pilot
     * volume for a once-per-user purge, the same trade purgeCrossTenantSubscriptions
     * already makes. Deliberately NO functional index: the write path normalises on
     * insert, so this fold exists only for pre-SEM-1 rows and will stop mattering.
     * (SQLite's lower() is ASCII-only where Postgres' is locale-aware — immaterial for
     * email addresses, noted so the driver difference isn't rediscovered as a bug.)
     */
    private function purgeCaseSignalPii(User $professional, ?string $lookupEmail): void
    {
        $emailLc = ($lookupEmail !== null && trim($lookupEmail) !== '')
            ? mb_strtolower(trim($lookupEmail))
            : null;

        try {
            DB::connection('pgsql')
                ->table('moderation.case_signals')
                ->where(function ($q) use ($professional, $emailLc) {
                    $q->where('reporter_user_id', $professional->id);

                    if ($emailLc !== null) {
                        $q->orWhereRaw('lower(trim(reporter_email)) = ?', [$emailLc]);
                    }
                })
                ->update([
                    'reporter_user_id' => null,
                    'reporter_email' => null,
                    'reason_details' => null,
                    'signal_data' => '{}',
                ]);
        } catch (\Throwable $e) {
            Log::error('Case signal PII erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * PRIV-4: Tombstone reported-user PII embedded in moderation.evidence payload.
     *
     * EvidenceSnapshotService::snapshotSite() writes the reported user's handle,
     * display_name, and site_subdomain into payload for evidence_type='content_snapshot'.
     * (This docblock previously also claimed `bio` — it never was written, and there is
     * no `bio` column anywhere in the schema. Corrected rather than defensively redacted:
     * redacting a key that is never present is dead code that reads as coverage.)
     * moderation.evidence has no FK to the reported user, so forceDelete's cascade never
     * reaches it — the PII would otherwise survive indefinitely (GDPR erasure gap).
     *
     * Tombstone strategy (overwrite with '[redacted]') rather than key-removal preserves
     * the payload shape so staff UI / EvidenceResource doesn't break on missing keys.
     * Non-PII fields (site_id, block_count, block_types, content_hash, captured_at) are
     * retained for case integrity. content_hash is intentionally NOT recomputed — the
     * original hash serves as tamper-evidence that redaction occurred post-snapshot.
     *
     * Day-one: only 'content_snapshot' (Site) evidence exists. Future snapshot types
     * (SiteMedia/Block/User) that embed reported-user PII in payload must be added here.
     */
    private function purgeReportedUserEvidencePii(User $professional): void
    {
        try {
            // Laravel's JSON where() emits json_extract() on SQLite and ->> on Postgres —
            // works identically on both drivers without raw SQL.
            $rows = Evidence::query()
                ->where('evidence_type', 'content_snapshot')
                ->where('payload->user_id', $professional->id)
                ->get();

            foreach ($rows as $evidence) {
                $payload = $evidence->payload;

                if (! is_array($payload)) {
                    continue;
                }

                // Redact PII keys; leave case-integrity fields (site_id, block_count,
                // block_types, content_hash, captured_at, user_id) intact.
                foreach (['handle', 'display_name', 'site_subdomain'] as $piiKey) {
                    if (array_key_exists($piiKey, $payload)) {
                        $payload[$piiKey] = '[redacted]';
                    }
                }

                $evidence->payload = $payload;
                $evidence->save();
            }
        } catch (\Throwable $e) {
            Log::error('Evidence payload PII erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * #P2-12: Delete global (user_id IS NULL) email_subscriptions matched by email_lc.
     *
     * user_id-linked rows are cascade-deleted by forceDelete via FK ON DELETE CASCADE.
     * Global rows (platform marketing list signups) are keyed only on email_lc and
     * require explicit deletion.
     */
    private function purgeGlobalEmailSubscriptions(User $professional, ?string $lookupEmail): void
    {
        if ($lookupEmail === null || trim($lookupEmail) === '') {
            return;
        }

        try {
            DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->whereNull('user_id')
                ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                ->delete();
        } catch (\Throwable $e) {
            // LIFE-103: same identifiability fix as purgeEarlyAccessSignup() (LIFE-102) —
            // the row being deleted has no user_id (it's the global/marketing-list row),
            // so the acting user must be logged explicitly.
            Log::error('Global email subscription erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * PRIV-7 Gap 1: Delete email_subscriptions rows that belong to OTHER users
     * but share the deleting user's email address.
     *
     * These cross-tenant rows arise when a professional creates a customer's
     * marketing subscription (user_id = other-pro) using an email that happens to
     * match the deleting user. DataExportPayloadBuilder::streamEmailSubscriptions()
     * surfaces them in GDPR exports keyed on email_lc, so erasure must reach them too.
     *
     * The $lookupEmail MUST be the pre-pseudonymisation email resolved by
     * resolveDeletedAccountEmail() — by the time purge() runs, primary_email
     * is already "deleted+{id}@partna.au" and would match nothing.
     *
     * Note: broadcast_email_receipts child rows are NOT deleted here — the
     * DINT-2 FK (ON DELETE CASCADE, migration 20260624010000) cascades them
     * automatically when the parent subscription row is deleted.
     */
    private function purgeCrossTenantSubscriptions(User $professional, ?string $lookupEmail): void
    {
        if ($lookupEmail === null || trim($lookupEmail) === '') {
            return;
        }

        try {
            DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->whereNotNull('user_id')
                ->where('user_id', '!=', $professional->id)
                ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Cross-tenant email subscription erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * PRIV-3: Delete analytics.item_views rows for this professional.
     *
     * user_id is a denormalised nullable column (no FK to core.users — see the
     * table's migration comment: nullable = fail-open write path), so forceDelete's
     * cascade never reaches it. Left alone, a deleted user's visitor ip_hash/user_agent
     * would otherwise survive until the next PurgeRawAnalyticsEvents sweep (up to the
     * 90-day analytics_raw_event_retention_days window) instead of being erased
     * immediately on account deletion.
     */
    private function purgeItemViewsPii(User $professional): void
    {
        try {
            DB::connection('pgsql')
                ->table('analytics.item_views')
                ->where('user_id', $professional->id)
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Item view analytics erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            // LIFE-5: surface to Nightwatch — matches purge()'s top-level pattern.
            report($e);
        }
    }

    /**
     * Delete analytics.action_events rows for this professional — same
     * PRIV-3 shape as purgeItemViewsPii() above (user_id is a denormalised
     * nullable column, no FK to core.users), same reasoning: without an
     * explicit purge, a deleted user's visitor ip_hash/user_agent/session_id
     * would otherwise survive until the next PurgeRawAnalyticsEvents sweep.
     */
    private function purgeActionEventsPii(User $professional): void
    {
        try {
            DB::connection('pgsql')
                ->table('analytics.action_events')
                ->where('user_id', $professional->id)
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Action event analytics erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    /**
     * #PRIV-1: erase the ingest charge-once ledger's captured payloads.
     *
     * ingest.effects.meta stores the vendor result INLINE up to 1 MB
     * (EffectLedger::once()), so a google-business effect's meta carries verbatim
     * reviewer names and text — third-party PII with no other erasure path. The FK
     * added by migration 20260729120002 is ON DELETE SET NULL, NOT CASCADE, on
     * purpose: cascading would destroy the spend record we still owe reconciliation
     * for. Erasure is therefore this explicit call, which MUST run before
     * forceDelete() while ingest.sources still resolves this user's rows.
     *
     * body_ref: no writer exists today (EffectLedger never sets it — see its
     * RESULT_INLINE_MAX_BYTES docblock: the P7 off-row drivers are unbuilt, and
     * there is no ingest disk in config/filesystems.php). Rather than delete files
     * from a guessed disk, this reports loudly if the column is ever populated, so
     * the day P7 lands this gap pages instead of silently under-erasing. Wire the
     * Storage::disk()->delete() loop HERE when it does.
     */
    private function purgeIngestEffects(User $professional): void
    {
        try {
            $sourceIds = DB::connection('pgsql')
                ->table('ingest.sources')
                ->where('user_id', $professional->id)
                ->pluck('id');

            if ($sourceIds->isEmpty()) {
                return;
            }

            $bodyRefs = DB::connection('pgsql')
                ->table('ingest.effects')
                ->whereIn('source_id', $sourceIds)
                ->whereNotNull('body_ref')
                ->pluck('body_ref');

            if ($bodyRefs->isNotEmpty()) {
                report(new \RuntimeException(
                    'AccountDeletionService::purgeIngestEffects() — '.$bodyRefs->count()
                    .' ingest.effects rows carry a body_ref but no off-row deletion path exists yet; '
                    .'the referenced response bodies survive this erasure.'
                ));
            }

            DB::connection('pgsql')
                ->table('ingest.effects')
                ->whereIn('source_id', $sourceIds)
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Ingest effects erasure failed during account purge', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    /**
     * Check for unsettled obligations. Returns reason codes.
     * (Commerce obligations removed — individuals have no payouts.)
     *
     * @return array<string>
     */
    private function checkObligations(User $professional): array
    {
        return [];
    }

    /**
     * Call Supabase Admin API to delete an auth user. 404 is treated as success
     * (user already deleted). Any other non-2xx response is a failure.
     */
    /**
     * Does this GoTrue identity also hold a core.partna_staff row?
     *
     * Queried at purge time rather than passed in, because the caller that
     * matters (the nightly PurgeSoftDeleted sweep) has no idea the account it is
     * collecting is anyone's staff login.
     */
    private function authUserHoldsStaffRow(string $authUserId): bool
    {
        return PartnaStaff::query()
            ->where('auth_user_id', $authUserId)
            ->exists();
    }

    private function deleteSupabaseAuthUser(string $authUserId): bool
    {
        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $serviceKey = (string) config('supabase.service_role_key');

        if ($baseUrl === '' || $serviceKey === '') {
            Log::error('Supabase credentials not configured; cannot delete auth user', [
                'auth_user_id' => $authUserId,
            ]);

            return false;
        }

        // Bounded so a black-holed/degraded GoTrue host can't hang this synchronous
        // staff request (or, on the nightly command, the whole chunk() batch)
        // indefinitely. connectTimeout is clamped to the total budget — a house
        // default of 3s would exceed a shorter SUPABASE_HTTP_TIMEOUT_SECONDS set
        // during an incident (mirrors SafeUrlFetcher's per-hop clamp).
        $timeoutSeconds = (int) config('supabase.http_timeout_seconds', 5);

        try {
            $response = Http::withHeaders([
                'apikey' => $serviceKey,
                'Authorization' => 'Bearer '.$serviceKey,
            ])
                ->timeout($timeoutSeconds)
                ->connectTimeout(min(3, $timeoutSeconds))
                ->delete("{$baseUrl}/auth/v1/admin/users/{$authUserId}");
        } catch (ConnectionException $e) {
            // Funnel into the same failure branch a non-2xx response takes below:
            // purge() logs EVENT_PURGE_FAILED + report()s and returns false, which
            // the staff route maps to a retryable 502 and the nightly command
            // continues to the next row instead of aborting the chunk.
            Log::error('Supabase auth user deletion connection failed', [
                'auth_user_id' => $authUserId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->status() === 404) {
            return true;
        }

        if (! $response->successful()) {
            // Privacy: GoTrue 4xx responses can include the deleted user's email,
            // user_metadata, and phone — drop the body from the log context. The
            // auth_user_id + status are enough to diagnose without persisting PII
            // into log retention windows that GDPR erasure can't reach.
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Enumerate all site media for this professional and clean up R2 artifacts.
     * Videos are dispatched async (many HLS segments). Images and documents are
     * deleted synchronously (single file per record). Failures are logged and
     * skipped — a storage error must never block the DB deletion.
     *
     * Returns the R2 base paths of every video whose cleanup was dispatched, so
     * purge() can record them in the EVENT_PURGED audit row (the ledger). After
     * forceDelete() the site_media rows are gone — the ledger is then the only
     * surviving record of which R2 objects an exhausted DeleteMediaArtifactsJob
     * left behind, and the daily gdpr sweep re-deletes them. (P1-08)
     *
     * Public: a second caller, PruneExpiredPreAccountBuilds, reuses this seam so
     * an expired provisional user's R2 media is cleaned up before its row cascade
     * (DB cascades never touch storage) — same requirement as purge()'s Step 2.
     *
     * @return list<string> video artifact base paths
     */
    public function purgeMediaArtifacts(User $professional): array
    {
        $site = Site::query()->where('user_id', $professional->id)->first();

        if (! $site) {
            return [];
        }

        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        $videoPaths = [];

        foreach ($mediaItems as $media) {
            try {
                match ($media->media_type) {
                    SiteMedia::MEDIA_TYPE_VIDEO => $videoPaths[] = $this->purgeVideoArtifacts($media),
                    SiteMedia::MEDIA_TYPE_DOCUMENT => $this->purgeDocumentArtifact($media),
                    default => $this->purgeImageArtifacts($media),
                };
            } catch (\Throwable $e) {
                Log::warning('R2 artifact cleanup failed for media item during account purge', [
                    'user_id' => $professional->id,
                    'media_id' => $media->id,
                    'media_type' => $media->media_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // purgeVideoArtifacts returns null for a path-less row; drop those.
        return array_values(array_filter(
            $videoPaths,
            static fn ($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Dispatch async cleanup for a video's R2 artifacts.
     *
     * @return string|null the dispatched base path, or null when the row has no path
     */
    private function purgeVideoArtifacts(SiteMedia $media): ?string
    {
        if (! $media->path) {
            return null;
        }

        DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->usage);

        return $media->path;
    }

    private function purgeImageArtifacts(SiteMedia $media): void
    {
        app(ImageVariantService::class)->deleteVariants($media->id, $media->path ?: null);
    }

    private function purgeDocumentArtifact(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        $disk = Storage::disk((string) config('partna.media_disk'));
        if ($disk->exists($media->path)) {
            $disk->delete($media->path);
        }
    }

    /**
     * Re-hydrate primary_email from the most recent snapshot-bearing audit row.
     * Used by cancel() / adminCancel() to undo the pseudonymisation applied at
     * confirm time. No-op when no snapshot exists (request → cancel before
     * confirmation never overwrote the live row).
     *
     * CONFIRMED and ADMIN_INITIATED are the only two events whose snapshot is
     * captured immediately BEFORE pseudonymiseAccountPii() runs — both are
     * written by executeConfirmation(), which confirm() and adminInitiate()
     * share. Filtering on CONFIRMED alone left every staff-initiated deletion
     * unrestorable: adminCancel() found no row, the guard below no-opped, and
     * primary_email stayed "deleted+{id}@partna.au". ResolvesDeletedEmail hit
     * the same gap and also matches REQUESTED, which is redundant here: callers
     * only run while status is pending_deletion, so a newer CONFIRMED or
     * ADMIN_INITIATED row always exists to win the ordering.
     *
     * Do NOT widen this further: CANCELLED/ADMIN_CANCELLED are written AFTER
     * this restore (self-referential), and PURGE_FAILED is written while the
     * row is already pseudonymised — it would be the newest match and would
     * restore the placeholder over itself.
     */
    private function restoreEmailFromAuditSnapshot(User $professional): void
    {
        $snapshotEmail = DB::connection('pgsql')
            ->table('audit.user_deletion_audit')
            ->where('user_id', $professional->id)
            ->whereIn('event', [
                UserDeletionAuditEntry::EVENT_CONFIRMED,
                UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            ])
            ->orderByDesc('created_at')
            ->value('professional_email_snapshot');

        if (is_string($snapshotEmail) && $snapshotEmail !== '') {
            $professional->forceFill(['primary_email' => $snapshotEmail])->save();
        }
    }

    /**
     * Append an audit row. Captures handle/email snapshots so the row survives
     * the professional's eventual hard delete. Actor parameters identify who
     * triggered this event — the professional themselves (self-service),
     * a staff admin (support-initiated), or the system (daily purge command).
     */
    public function logAuditEvent(
        User $professional,
        string $event,
        ?Request $request = null,
        array $metadata = [],
        string $actorType = UserDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
        ?string $actorId = null,
        ?string $actorHandle = null,
        ?string $reason = null,
    ): void {
        // SEC-3: forceCreate — user_id/actor_id/ip_address/actor_handle_snapshot/
        // professional_email_snapshot are no longer fillable (server-managed on
        // this append-only audit table).
        UserDeletionAuditEntry::forceCreate([
            'user_id' => $professional->id,
            'professional_handle_snapshot' => (string) ($professional->handle ?? ''),
            'professional_email_snapshot' => (string) ($professional->primary_email ?? ''),
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_handle_snapshot' => $actorHandle,
            'reason' => $reason,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => ! empty($metadata) ? $metadata : null,
        ]);
    }
}
