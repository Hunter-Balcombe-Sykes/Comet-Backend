<?php

namespace App\Jobs\Account;

use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Models\Core\Professional\User;
use Illuminate\Bus\Queueable;
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
class SendAccountDeletionRequestMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 15;

    public function __construct(
        public readonly string $professionalId,
        public readonly string $rawToken,
    ) {
        $this->onQueue('notifications');
        // afterCommit prevents the worker from picking up the job before
        // AccountDeletionService::request()'s wrapping DB::transaction commits.
        // Set on the instance (not as a typed property) because the Queueable
        // trait already declares $afterCommit as an untyped property.
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $professional = User::query()->find($this->professionalId);
        if ($professional === null) {
            // User was purged/cancelled between dispatch and execution — nothing to send.
            return;
        }

        $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/account/deletion/confirm?token='.$this->rawToken;

        Mail::to($professional->primary_email)->send(
            new AccountDeletionRequestedMail(
                displayName: (string) ($professional->display_name ?? 'there'),
                confirmationUrl: $confirmationUrl,
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
        $tokenHash = hash('sha256', $this->rawToken);
        $rowsCleared = DB::connection('pgsql')
            ->table('core.users')
            ->where('id', $this->professionalId)
            ->where('deletion_token_hash', $tokenHash)
            ->update([
                'deletion_token_hash' => null,
                'deletion_requested_at' => null,
            ]);

        Log::error('Account deletion request mail failed', [
            'professional_id' => $this->professionalId,
            'token_cleared' => $rowsCleared > 0,
            'error' => $e->getMessage(),
        ]);
    }
}
