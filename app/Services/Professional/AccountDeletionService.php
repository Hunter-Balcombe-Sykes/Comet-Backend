<?php

namespace App\Services\Professional;

use App\Jobs\DeleteMediaArtifactsJob;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// V2: All account-deletion business logic. Called from
// ProfessionalAccountDeletionController for request/confirm/cancel flows, and
// from PurgeSoftDeleted command for hard-delete after grace period.
class AccountDeletionService
{
    /**
     * Initiate a deletion request. Checks preconditions, stores hashed token,
     * sends confirmation email. Rolls back token storage if mail send fails.
     *
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>}
     */
    public function request(Professional $professional, Request $request): array
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

        $professional->update([
            'deletion_token_hash' => $tokenHash,
            'deletion_requested_at' => now(),
        ]);

        $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/account/deletion/confirm?token='.$rawToken;

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionRequestedMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    confirmationUrl: $confirmationUrl,
                )
            );
        } catch (\Throwable $e) {
            // Mail failed — roll back token so user can retry cleanly.
            $professional->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

            Log::error('Account deletion request mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 503,
                'error' => 'Failed to send confirmation email. Please try again.',
            ];
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_REQUESTED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Confirm deletion via token. Snapshots previous status, flips to
     * pending_deletion, deletes integration credentials, schedules Stripe
     * cancel-at-period-end, sends scheduled mail.
     *
     * @return array{success: bool, code: int, error?: string, deletes_at?: string}
     */
    public function confirm(Professional $professional, string $rawToken, Request $request): array
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

        $deletesAt = $this->executeConfirmation($professional);

        // Order matters: audit row must capture the REAL primary_email before we
        // pseudonymise. pseudonymiseAccountPii() is a one-way write that destroys
        // the live PII, so it always runs after the audit snapshot is durable.
        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CONFIRMED, $request);
        $this->pseudonymiseAccountPii($professional);

        return [
            'success' => true,
            'code' => 200,
            'deletes_at' => $deletesAt->toIso8601String(),
        ];
    }

    /**
     * Apply the confirmed deletion: snapshot status, flip to pending_deletion,
     * revoke integration credentials, schedule Stripe cancel-at-period-end,
     * send scheduled email. Shared by self-service confirm() and admin
     * adminInitiate(). Returns the deletes_at timestamp. PII pseudonymisation
     * is intentionally deferred to a separate call so the EVENT_CONFIRMED /
     * EVENT_ADMIN_INITIATED audit row captures the real email first.
     */
    private function executeConfirmation(Professional $professional): Carbon
    {
        $retentionDays = (int) config('partna.soft_delete_retention_days', 30);
        $deletesAt = now()->addDays($retentionDays);
        $previousStatus = (string) ($professional->status ?? 'active');

        // Snapshot the real email before any state change so the "deletion scheduled"
        // mail still reaches the user even after we pseudonymise downstream.
        $realEmail = (string) ($professional->primary_email ?? '');

        DB::transaction(function () use ($professional, $previousStatus) {
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
        });

        $cancelUrl = rtrim((string) config('app.frontend_url'), '/').'/account/deletion/cancel';

        try {
            Mail::to($realEmail)->send(
                new AccountDeletionScheduledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                    deletesAt: $deletesAt->toDayDateTimeString(),
                    cancelUrl: $cancelUrl,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion scheduled mail failed', [
                'professional_id' => $professional->id,
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
     * in core.professional_deletion_audit.professional_email_snapshot so support can
     * re-identify the user if they email to cancel.
     */
    private function pseudonymiseAccountPii(Professional $professional): void
    {
        $professional->forceFill([
            'phone' => 'redacted',
            'primary_email' => "deleted+{$professional->id}@partna.au",
            'first_name' => 'Deleted',
            'last_name' => null,
            'public_contact_email' => null,
            'public_contact_number' => null,
            'bio' => null,
            'about' => (object) [], // empty JSON object — satisfies the jsonb_typeof = 'object' constraint
            'location_street_address' => null,
            'location_postcode' => null,
            'location_city' => null,
            'location_state' => null,
            'location_country' => null,
        ])->save();
    }

    /**
     * Admin-initiated deletion. Skips the email-token confirm step and goes
     * straight to scheduling the 30-day grace period. Used when a professional
     * emails support requesting erasure (e.g., GDPR Article 17 request).
     *
     * @param  Professional  $professional  The user being deleted.
     * @param  string  $staffActorId  PartnaStaff.id of the admin invoking this.
     * @param  string  $staffActorHandle  Snapshot of staff name (or email) for audit.
     * @param  string  $reason  GDPR reason / support ticket reference (10–500 chars).
     * @param  bool  $overrideObligations  If true, proceed despite unpaid balance / pending payouts.
     * @return array{success: bool, code: int, error?: string, reasons?: array<string>, deletes_at?: string}
     */
    public function adminInitiate(
        Professional $professional,
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

        $deletesAt = $this->executeConfirmation($professional);

        $metadata = ! empty($obligations)
            ? ['obligations_overridden' => $obligations]
            : [];

        // Same audit-before-pseudonymise order as the self-service confirm() path.
        $this->logAuditEvent(
            $professional,
            ProfessionalDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            $request,
            $metadata,
            ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );
        $this->pseudonymiseAccountPii($professional);

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
        Professional $professional,
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

        // Recovery: same email-snapshot restore as the self-service cancel path.
        $this->restoreEmailFromAuditSnapshot($professional);

        DB::transaction(function () use ($professional, $previousStatus) {
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
                ->where('professional_id', $professional->id)
                ->lockForUpdate()
                ->first();
            if ($site && $site->unpublished_at !== null) {
                $site->update([
                    'is_published' => true,
                    'unpublished_at' => null,
                ]);
            }
        });

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logAuditEvent(
            $professional,
            ProfessionalDeletionAuditEntry::EVENT_ADMIN_CANCELLED,
            $request,
            [],
            ProfessionalDeletionAuditEntry::ACTOR_TYPE_STAFF_ADMIN,
            $staffActorId,
            $staffActorHandle,
            $reason,
        );

        return ['success' => true, 'code' => 200];
    }

    /**
     * Cancel a pending deletion during the grace period. Restores previous
     * status, clears deletion timestamps, attempts to reverse Stripe
     * cancel-at-period-end, sends cancellation mail.
     *
     * @return array{success: bool, code: int}
     */
    public function cancel(Professional $professional, Request $request): array
    {
        $previousStatus = $professional->deletion_previous_status;
        if (! is_string($previousStatus) || $previousStatus === '') {
            $previousStatus = 'active';
        }

        // Recovery: confirm() pseudonymises primary_email — restore the real one
        // from the EVENT_CONFIRMED audit snapshot so the cancel mail reaches the
        // user and downstream audit rows capture the real address. No-op if the
        // user is cancelling before confirm (no snapshot row exists yet).
        $this->restoreEmailFromAuditSnapshot($professional);

        DB::transaction(function () use ($professional, $previousStatus) {
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
                ->where('professional_id', $professional->id)
                ->lockForUpdate()
                ->first();
            if ($site && $site->unpublished_at !== null) {
                $site->update([
                    'is_published' => true,
                    'unpublished_at' => null,
                ]);
            }
        });

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logAuditEvent($professional, ProfessionalDeletionAuditEntry::EVENT_CANCELLED, $request);

        return ['success' => true, 'code' => 200];
    }

    /**
     * Hard-delete a professional whose grace period has elapsed. Called by
     * PurgeSoftDeleted command. Returns false on any failure so the caller
     * can retry on the next daily run.
     */
    public function purge(Professional $professional): bool
    {
        $handleSnapshot = (string) ($professional->handle ?? '');
        $emailSnapshot = (string) ($professional->primary_email ?? '');
        $authUserId = (string) ($professional->auth_user_id ?? '');

        // Step 1: delete Supabase auth user. If this fails, do NOT hard-delete
        // the DB row — we'd end up with an orphaned auth user and no way to retry.
        if ($authUserId !== '' && ! $this->deleteSupabaseAuthUser($authUserId)) {
            $this->logAuditEvent(
                $professional,
                ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'supabase_deletion_failed'],
                ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            return false;
        }

        // Step 2: clean up R2 artifacts before the DB cascade deletes the rows.
        // forceDelete() cascades to site_media, but DB cascades do not touch R2 storage.
        $this->purgeMediaArtifacts($professional);

        // Step 3: bust the public site cache (15-min TTL) so a just-purged site
        // stops serving stale payloads to public requests the instant we delete.
        // invalidateSite() handles the main subdomain + all aliases in one call.
        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            try {
                app(SiteCacheService::class)->invalidateSite($site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed during account purge', [
                    'professional_id' => $professional->id,
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Step 4: hard-delete professional row. DB handles cascades (42 FKs CASCADE,
        // 3 previously-RESTRICT FKs now SET NULL). forceDelete triggers model events.
        try {
            $professional->forceDelete();
        } catch (\Throwable $e) {
            Log::error('Professional forceDelete failed during purge', [
                'professional_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
            $this->logAuditEvent(
                $professional,
                ProfessionalDeletionAuditEntry::EVENT_PURGE_FAILED,
                null,
                ['reason' => 'force_delete_failed', 'error' => $e->getMessage()],
                ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
            );

            return false;
        }

        // Direct create (not logAuditEvent) — the professional row was just
        // force-deleted, so professional_id must be NULL to satisfy the FK.
        // Snapshots taken at the top of purge() preserve identity for forensics.
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => null,
            'professional_handle_snapshot' => $handleSnapshot,
            'professional_email_snapshot' => $emailSnapshot,
            'event' => ProfessionalDeletionAuditEntry::EVENT_PURGED,
            'actor_type' => ProfessionalDeletionAuditEntry::ACTOR_TYPE_SYSTEM,
        ]);

        return true;
    }

    /**
     * Check for unsettled obligations. Returns reason codes.
     * (Commerce obligations removed — individuals have no payouts.)
     *
     * @return array<string>
     */
    private function checkObligations(Professional $professional): array
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
            Log::error('Supabase auth user deletion failed', [
                'auth_user_id' => $authUserId,
                'status' => $response->status(),
                'body' => $response->body(),
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
     */
    private function purgeMediaArtifacts(Professional $professional): void
    {
        $site = Site::query()->where('professional_id', $professional->id)->first();

        if (! $site) {
            return;
        }

        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();

        foreach ($mediaItems as $media) {
            try {
                match ($media->media_type) {
                    SiteMedia::MEDIA_TYPE_VIDEO => $this->purgeVideoArtifacts($media),
                    SiteMedia::MEDIA_TYPE_DOCUMENT => $this->purgeDocumentArtifact($media),
                    default => $this->purgeImageArtifacts($media),
                };
            } catch (\Throwable $e) {
                Log::warning('R2 artifact cleanup failed for media item during account purge', [
                    'professional_id' => $professional->id,
                    'media_id' => $media->id,
                    'media_type' => $media->media_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function purgeVideoArtifacts(SiteMedia $media): void
    {
        if (! $media->path) {
            return;
        }

        DeleteMediaArtifactsJob::dispatch($media->id, $media->path, (string) $media->pool);
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
    private function restoreEmailFromAuditSnapshot(Professional $professional): void
    {
        $snapshotEmail = DB::connection('pgsql')
            ->table('core.professional_deletion_audit')
            ->where('professional_id', $professional->id)
            ->where('event', ProfessionalDeletionAuditEntry::EVENT_CONFIRMED)
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
        Professional $professional,
        string $event,
        ?Request $request = null,
        array $metadata = [],
        string $actorType = ProfessionalDeletionAuditEntry::ACTOR_TYPE_PROFESSIONAL,
        ?string $actorId = null,
        ?string $actorHandle = null,
        ?string $reason = null,
    ): void {
        ProfessionalDeletionAuditEntry::create([
            'professional_id' => $professional->id,
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
