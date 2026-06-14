<?php

namespace App\Jobs\Account;

use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Sends the deletion-confirmation email asynchronously. Preserves the
// "user holds a token IFF the confirmation email was sent" invariant via
// failed(): on permanent mail failure the token hash is cleared so the user
// can re-request cleanly. afterCommit ensures the wrapping DB::transaction in
// AccountDeletionService::request() commits before this job is picked up,
// otherwise the worker could observe a not-yet-persisted token row.
//
// ShouldBeEncrypted: the confirmationUrl carries the raw deletion token as a
// query param (the consume side hash-matches it against deletion_token_hash, so
// the URL MUST contain the raw credential to work). That makes the URL a bearer
// secret — possession confirms account deletion. Marking the job encrypted means
// Laravel ciphers the serialized payload before it lands in Redis, so Horizon
// snapshots and Redis backup/monitoring tooling only ever see ciphertext. The
// bare raw token is never carried as its own field; failed() works off the
// non-reversible tokenHash, never the token or the URL.
class SendAccountDeletionRequestMailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 15;

    /**
     * @param  string  $userId  core.users PK whose deletion is being confirmed.
     * @param  string  $confirmationUrl  Pre-built link (carries the raw token); encrypted at rest via ShouldBeEncrypted.
     * @param  string  $tokenHash  sha256 of the raw token — the only token reference failed() ever touches.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $confirmationUrl,
        public readonly string $tokenHash,
    ) {
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
        // afterCommit prevents the worker from picking up the job before
        // AccountDeletionService::request()'s wrapping DB::transaction commits.
        // Set on the instance (not as a typed property) because the Queueable
        // trait already declares $afterCommit as an untyped property.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        // Idempotency guard: lock the row so two concurrent workers (retry overlapping
        // with the original, or Horizon scale-out) cannot both read deletion_mail_sent_at
        // = null and both deliver the email. Mirrors SendEnquiryConfirmationJob.
        $professional = DB::transaction(function () {
            $user = User::query()->lockForUpdate()->find($this->userId);
            if ($user === null) {
                return null;
            }

            // Token mismatch: the user re-requested with a fresh token (or cancelled),
            // so this job's tokenHash no longer matches — bail without sending.
            if ($user->deletion_token_hash !== $this->tokenHash) {
                return false;
            }

            if ($user->deletion_mail_sent_at !== null) {
                return false; // already sent on a previous attempt
            }

            // Stamp atomically under the lock (at-most-once: if the mail send later
            // throws, the retry will skip it — deliberate). Mirrors the confirmation
            // job's choice: no double-send takes priority over guaranteed delivery;
            // permanent failures surface via report() in failed().
            $user->forceFill(['deletion_mail_sent_at' => now()])->saveQuietly();

            return $user;
        });

        if ($professional === null) {
            // User was purged/cancelled between dispatch and execution — nothing to send.
            return;
        }

        if ($professional === false) {
            return; // already sent, or token was rotated — skip silently
        }

        Mail::to($professional->primary_email)->send(
            new AccountDeletionRequestedMail(
                displayName: (string) ($professional->display_name ?? 'there'),
                confirmationUrl: $this->confirmationUrl,
            )
        );
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        // Token rotation guard: only clear the deletion token if it still matches
        // the hash this job was dispatched with. If the user already re-requested
        // (writing a fresh token), the WHERE clause matches zero rows and the new
        // token survives — preventing this failed job from trampling a healthy retry.
        $rowsCleared = DB::connection('pgsql')
            ->table('core.users')
            ->where('id', $this->userId)
            ->where('deletion_token_hash', $this->tokenHash)
            ->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

        // Logs the non-reversible hash + user id only — never the raw token or the
        // confirmationUrl that embeds it, so log retention never holds the bearer
        // credential.
        Log::error('Account deletion request mail failed', [
            'user_id' => $this->userId,
            'token_cleared' => $rowsCleared > 0,
            'error' => $e->getMessage(),
        ]);
    }
}
