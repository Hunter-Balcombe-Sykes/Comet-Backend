<?php

namespace App\Jobs\Routing;

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\SuggestionApplier;
use App\Routing\Verification\LinkVerifier;
use App\Routing\Verification\VerificationVerdict;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The L2 half of an accept, for a link whose detector matched nothing but the
 * brand's domain (L1 WEAK). The person has already said yes; this decides
 * whether we are entitled to claim the page is theirs.
 *
 * Runs on a queue because it makes a network call and LinkProbeWorker's rule —
 * no network work in a request cycle — is not negotiable. The intent sits in
 * 'verifying' meanwhile, which holds its slot so a concurrent harvest cannot
 * open a duplicate.
 *
 * EXACTLY ONE of three things happens, and two of them write the connection:
 *
 *   Found     → apply, verification_state 'verified'
 *   Blocked   → apply, verification_state 'unverified'   (save-and-flag)
 *   NotFound  → refuse: intent 'blocked' + block_reason 'not_found', no write
 *
 * failed() is the fourth path and it is deliberately the SAME as Blocked: if
 * this job dies — a queue restart, a bad deploy, an adapter that throws past
 * its retries — the person's accepted link must still land. Being unable to
 * check has never been evidence against a link.
 */
class VerifyLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Short and rising, because a person is waiting on the card to stop saying
     * "checking". Three tries at 5s then 15s puts the last attempt ~20s out,
     * inside the window someone will actually sit through — a minute-scale
     * backoff would be indistinguishable from the job never running.
     *
     * The retries are for a flaky upstream, not for a definite answer: a
     * NotFound is a RESULT and returns without throwing, so it never reaches
     * this. Only a throw does, and failing all three is the same as Blocked —
     * the link still lands, unverified (see failed() below).
     */
    public array $backoff = [5, 15];

    /** Bounded: a person is waiting on the other end of this. */
    public int $timeout = 30;

    public function __construct(
        public readonly string $userId,
        public readonly string $intentId,
    ) {}

    public function handle(LinkVerifier $verifier, SuggestionApplier $applier): void
    {
        $user = User::find($this->userId);
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }

        $intent = $this->claimIntent();
        if ($intent === null) {
            // Not ours to settle: dismissed, superseded, or already resolved by
            // a retry of this same job. Both are ordinary races, not faults.
            return;
        }

        $surface = CompiledCatalog::surface((string) $intent->surface_key);
        if ($surface === null) {
            $this->refuse($intent, 'unservable');

            return;
        }

        $verdict = $verifier->verify((string) $intent->surface_key, (string) $intent->canonical_url);

        Log::info('routing.verify.resolved', [
            'user_id' => $this->userId,
            'surface_key' => $intent->surface_key,
            'verdict' => $verdict->value,
        ]);

        if ($verdict === VerificationVerdict::NotFound) {
            $this->refuse($intent, 'not_found');

            return;
        }

        // Hand back to the ONE applier every accept goes through, so the
        // capability re-check, the incumbent demotion, the alias fold and the
        // intent settle all behave identically whether or not the link took
        // this detour. It expects the intent in an acceptable state, so the
        // 'verifying' hold is released first.
        DB::table('routing.source_intents')
            ->where('id', $intent->id)
            ->where('user_id', $user->id)
            ->update(['state' => 'proposed', 'updated_at' => now()]);

        $connection = $applier->apply($user, $intent, $surface);

        $connection->forceFill([
            'verification_state' => $verdict === VerificationVerdict::Found
                ? IntegrationConnection::VERIFICATION_VERIFIED
                : IntegrationConnection::VERIFICATION_UNVERIFIED,
        ])->save();
    }

    /**
     * Take the intent out of 'verifying' ownership atomically, so two runs of
     * this job (a retry racing a queue redelivery) cannot both apply it. The
     * WHERE on state is the claim.
     */
    private function claimIntent(): ?object
    {
        $intent = DB::table('routing.source_intents')
            ->where('id', $this->intentId)
            ->where('user_id', $this->userId)
            ->where('state', 'verifying')
            ->first();

        return $intent ?: null;
    }

    private function refuse(object $intent, string $reason): void
    {
        DB::table('routing.source_intents')
            ->where('id', $intent->id)
            ->where('user_id', $this->userId)
            ->update([
                'state' => 'blocked',
                'block_reason' => $reason,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * The job died. Save-and-flag rather than leave the person's accepted link
     * stranded in 'verifying' forever — see the class docblock.
     */
    public function failed(?Throwable $e): void
    {
        $user = User::find($this->userId);
        $intent = $this->claimIntent();

        if ($user === null || $intent === null) {
            return;
        }

        $surface = CompiledCatalog::surface((string) $intent->surface_key);
        if ($surface === null) {
            $this->refuse($intent, 'unservable');

            return;
        }

        try {
            DB::table('routing.source_intents')
                ->where('id', $intent->id)
                ->where('user_id', $user->id)
                ->update(['state' => 'proposed', 'updated_at' => now()]);

            $connection = app(SuggestionApplier::class)->apply($user, $intent, $surface);
            $connection->forceFill(['verification_state' => IntegrationConnection::VERIFICATION_UNVERIFIED])->save();
        } catch (Throwable $inner) {
            // Last resort. The intent goes back to 'proposed' rather than
            // staying 'verifying': a suggestion the person can accept again is
            // strictly better than one nothing will ever move.
            report($inner);

            DB::table('routing.source_intents')
                ->where('id', $intent->id)
                ->where('user_id', $user->id)
                ->update(['state' => 'proposed', 'updated_at' => now()]);
        }
    }
}
