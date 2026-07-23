<?php

namespace App\Jobs\Account;

use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\User\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
// ShouldBeUnique (R3-JOB-4): the real duplicate-dispatch guard is the lock, not
// the lockForUpdate transaction below (see handle()'s comment). uniqueId() is
// safe to hold in plaintext — see its own docblock for why.
//
// ShouldBeEncrypted: the confirmationUrl carries the raw deletion token as a
// query param (the consume side hash-matches it against deletion_token_hash, so
// the URL MUST contain the raw credential to work). That makes the URL a bearer
// secret — possession confirms account deletion. Marking the job encrypted means
// Laravel ciphers the serialized payload before it lands in Redis, so Horizon
// snapshots and Redis backup/monitoring tooling only ever see ciphertext. The
// bare raw token is never carried as its own field; failed() works off the
// non-reversible tokenHash, never the token or the URL. Note this protection is
// scoped to the queue payload only — it does NOT extend to the ShouldBeUnique
// lock key, which is why uniqueId() must never embed the raw token or the URL.
class SendAccountDeletionRequestMailJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 15;

    // 15 min. Worst-case job-owned window is 3 x 15s timeout + 30s + 120s backoff = 195s
    // ($backoff[2] is never consumed at $tries = 3); config/horizon.php budgets 60s of
    // queue wait per attempt on `notifications`, so ~375s end to end. 900 is 2.4x that,
    // and 60x $timeout, which satisfies HorizonQueueCoverageTest's uniqueFor > timeout rule.
    // Bounded on purpose: an unset $uniqueFor makes UniqueLock::acquire() pass 0 to
    // RedisLock, which falls through to SETNX with no expiry — a SIGKILLed worker would
    // then strand this key in Redis DB 4 forever (RV-3, same run).
    public int $uniqueFor = 900;

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

    // Keyed on the token hash as well as the user id: a legitimate re-request always mints
    // a fresh random token (AccountDeletionService::request():83-84), so it hashes to a
    // different key and can never be coalesced away by a lock this job still holds.
    // Uses the sha256 verifier already stored in core.users.deletion_token_hash — never the
    // raw token and never $confirmationUrl. Unique-lock keys are plain Redis keys in cache
    // DB 4; ShouldBeEncrypted covers the queue payload only, not the lock key.
    public function uniqueId(): string
    {
        return $this->userId.':'.$this->tokenHash;
    }

    public function handle(): void
    {
        // Read guard, not a duplicate-dispatch guard: ShouldBeUnique now owns that job.
        // This transaction only decides whether THIS attempt should still send — the
        // token may have rotated (re-request) or the mail may already have gone out on a
        // prior attempt of this same job.
        $professional = DB::connection('pgsql')->transaction(function () {
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
                return false; // already delivered on a previous attempt
            }

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

        // At-least-once: the stamp lands only once the send has returned. A crash between
        // the two re-delivers on retry, which is strictly preferable to stranding a GDPR
        // deletion request behind a stamp for a mail that never went out. Same trade-off
        // SendTransactionalNotificationEmailJob already documents at :170-173.
        //
        // Written as a hash-guarded UPDATE rather than saveQuietly() on the model read
        // above: if the token rotated during the send, saveQuietly() would stamp the row
        // that now carries the NEW token and wedge that request — recreating this bug.
        //
        // Residual gap, documented not fixed: if Mail::send() above succeeded but this
        // UPDATE then throws on every attempt, deletion_mail_sent_at never lands, so
        // failed() below clears the token for a request whose email DID go out —
        // fail-closed and self-recoverable (the user hits a 404 and re-requests within 24h).
        DB::connection('pgsql')
            ->table('core.users')
            ->where('id', $this->userId)
            ->where('deletion_token_hash', $this->tokenHash)
            ->update(['deletion_mail_sent_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        report($e);

        // Token rotation guard, now also gated on deletion_mail_sent_at being unset: once
        // the stamp is post-send, deletion_mail_sent_at IS NOT NULL is proof of delivery —
        // clearing the token in that state would invalidate a confirmation link the user
        // demonstrably holds, turning a delivered mail into a dead 404. If the user already
        // re-requested (fresh token) OR the mail already went out on a prior attempt, the
        // WHERE clause matches zero rows and nothing is cleared.
        $rowsCleared = DB::connection('pgsql')
            ->table('core.users')
            ->where('id', $this->userId)
            ->where('deletion_token_hash', $this->tokenHash)
            ->whereNull('deletion_mail_sent_at')
            ->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

        // Logs the non-reversible hash + user id only — never the raw token or the
        // confirmationUrl that embeds it, so log retention never holds the bearer
        // credential. token_cleared = false now means either "token rotated" or "mail
        // already delivered" — both are the same "nothing to do" outcome, so no new key.
        Log::error('Account deletion request mail failed', [
            'user_id' => $this->userId,
            'token_cleared' => $rowsCleared > 0,
            'error' => $e->getMessage(),
        ]);
    }
}
