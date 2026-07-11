<?php

namespace App\Services\User;

use App\Jobs\Account\SendAccountDeletionRequestMailJob;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Models\Moderation\Evidence;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\User\Concerns\ResolvesDeletedEmail;
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
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * queues the confirmation email. Token write + job dispatch + audit log
     * commit atomically: if dispatch infrastructure fails, the token write
     * rolls back automatically — no manual cleanup, no DEL-2 race window.
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
                $professional->update([
                    'deletion_token_hash' => $tokenHash,
                    'deletion_requested_at' => now(),
                    // Reset sent_at so the idempotency guard in the job allows re-delivery —
                    // mirrors the subscription reset-on-re-subscribe precedent in PublicEmailSubscriptionController.
                    'deletion_mail_sent_at' => null,
                ]);

                // Job dispatch runs inside the transaction so a dispatch infrastructure
                // failure (e.g., Redis down) throws and rolls back the token write —
                // no orphaned token left on the row. afterCommit on the job class then
                // delays the worker pickup until this transaction commits.
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
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

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

            $professional->update([
                'deletion_previous_status' => $previousStatus,
                'status' => 'pending_deletion',
                'deletion_confirmed_at' => now(),
                'deletion_token_hash' => null,
            ]);

            // Immediately take the public storefront offline so a deleted brand's
            // shop stops serving requests for the full 30-day grace period.
            // SiteObserver::saved() handles cache invalidation automatically.
            if ($professional->site) {
                $professional->site->update([
                    'is_published' => false,
                    'unpublished_at' => now(),
                ]);
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

        try {
            Mail::to($realEmail)->queue(
                new AccountDeletionScheduledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    deletesAt: $deletesAt->toDayDateTimeString(),
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

        return $deletesAt;
    }

    /**
     * One-way pseudonymisation of live PII columns on core.professionals.
     *
     * The 30-day grace period only needs handle, display_name, and auth_user_id to
     * keep the "undo deletion" recovery path working; the original email is preserved
     * in audit.user_deletion_audit.professional_email_snapshot so support can
     * re-identify the user if they email to cancel.
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
            $indexKey = "idempotency:index:{$authUserId}";

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

            $professional->update([
                'status' => $previousStatus,
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_previous_status' => null,
                'deletion_token_hash' => null,
            ]);

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
                $site->update([
                    'is_published' => true,
                    'unpublished_at' => null,
                ]);
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
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
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

        // Step 3b: resolve pre-pseudonymisation email for email-keyed erasure.
        // executeConfirmation() pseudonymises primary_email before purge runs —
        // the original is preserved in the deletion audit snapshot.
        $lookupEmail = $this->resolveDeletedAccountEmail($professional);

        // Step 3c–3g: erase PII from surfaces the DB cascade won't reach.
        // Each step is independently fault-tolerant — a failure must not block forceDelete.
        $this->purgeExportZips($professional);           // #P2-08: R2 export ZIPs
        $this->purgeWaitlistSignup($lookupEmail);        // #P2-09: waitlist signup row
        $this->purgeFeedbackRows($professional);         // #P2-10: feedback (FK is SET NULL, not CASCADE)
        $this->purgeCaseSignalPii($professional);        // #P2-11: reporter PII on moderation signals
        $this->purgeReportedUserEvidencePii($professional); // PRIV-4: reported-user PII in evidence payload
        $this->purgeGlobalEmailSubscriptions($lookupEmail);    // #P2-12: global (user_id IS NULL) subscriptions
        $this->purgeCrossTenantSubscriptions($professional, $lookupEmail); // PRIV-7 Gap 1: other-user-owned rows matching this email

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

        // Direct create (not logAuditEvent) — the professional row was just
        // force-deleted, so user_id must be NULL to satisfy the FK. The handle
        // snapshot is the top-of-purge value (handles are never pseudonymised);
        // the email uses the audit-resolved pre-pseudonymisation address so this
        // PURGED receipt records the real address, not the "deleted+{id}@" placeholder
        // that primary_email already holds by this point (SEM-1).
        UserDeletionAuditEntry::create([
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
        }
    }

    /**
     * #P2-09: Delete core.waitlist_signups row matched by email_lc.
     *
     * Waitlist rows are keyed on email, not user_id — no DB cascade reaches them.
     */
    private function purgeWaitlistSignup(?string $lookupEmail): void
    {
        if ($lookupEmail === null || trim($lookupEmail) === '') {
            return;
        }

        try {
            DB::connection('pgsql')
                ->table('core.waitlist_signups')
                ->where('email_lc', mb_strtolower(trim($lookupEmail)))
                ->delete();
        } catch (\Throwable $e) {
            Log::error('Waitlist signup erasure failed during account purge', [
                'error' => $e->getMessage(),
            ]);
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
     */
    private function purgeCaseSignalPii(User $professional): void
    {
        try {
            DB::connection('pgsql')
                ->table('moderation.case_signals')
                ->where('reporter_user_id', $professional->id)
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
        }
    }

    /**
     * PRIV-4: Tombstone reported-user PII embedded in moderation.evidence payload.
     *
     * EvidenceSnapshotService::snapshotSite() writes the reported user's handle,
     * display_name, bio, and site_subdomain into payload for evidence_type='content_snapshot'.
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
        }
    }

    /**
     * #P2-12: Delete global (user_id IS NULL) email_subscriptions matched by email_lc.
     *
     * user_id-linked rows are cascade-deleted by forceDelete via FK ON DELETE CASCADE.
     * Global rows (platform marketing list signups) are keyed only on email_lc and
     * require explicit deletion.
     */
    private function purgeGlobalEmailSubscriptions(?string $lookupEmail): void
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
            Log::error('Global email subscription erasure failed during account purge', [
                'error' => $e->getMessage(),
            ]);
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

        $response = Http::withHeaders([
            'apikey' => $serviceKey,
            'Authorization' => 'Bearer '.$serviceKey,
        ])->delete("{$baseUrl}/auth/v1/admin/users/{$authUserId}");

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
     * @return list<string> video artifact base paths
     */
    private function purgeMediaArtifacts(User $professional): array
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

        DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->pool);

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
     * Re-hydrate primary_email from the most recent EVENT_CONFIRMED audit row.
     * Used by cancel() / adminCancel() to undo the pseudonymisation applied at
     * confirm time. No-op when no confirmed snapshot exists (request → cancel
     * before confirmation never overwrote the live row).
     */
    private function restoreEmailFromAuditSnapshot(User $professional): void
    {
        $snapshotEmail = DB::connection('pgsql')
            ->table('audit.user_deletion_audit')
            ->where('user_id', $professional->id)
            ->where('event', UserDeletionAuditEntry::EVENT_CONFIRMED)
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
        UserDeletionAuditEntry::create([
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
