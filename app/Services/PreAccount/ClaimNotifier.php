<?php

namespace App\Services\PreAccount;

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\Notifications\ClaimDmChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// One "invite this person to claim their site" concept fanning out to every
// channel we have contact info for (spec §3.1). Email ships now; DM is a bound
// stub. Call AFTER any surrounding DB transaction commits (Mailable dispatch
// discipline — see EarlyAccessService::invite()).
class ClaimNotifier
{
    public function __construct(private readonly ClaimDmChannel $dm) {}

    public function notify(PreAccountBuild $build): void
    {
        // Atomically CLAIM the email send under a per-build advisory lock so a
        // double-click on POST /builds/{build}/invite — or an auto-invite job
        // racing a manual send — can't both pass the invited_at check and both
        // email the lead. Stamping invited_at is what "wins" the race; the
        // loser re-reads it set and bails. We stamp BEFORE queueing (not after,
        // as a naive retry-safety design would) precisely so the winner is
        // decided atomically; delivery retries are the mail queue's job.
        $fresh = DB::connection('pgsql')->transaction(function () use ($build) {
            DB::connection('pgsql')->select('select pg_advisory_xact_lock(hashtext(?))', ["claim-invite:{$build->id}"]);

            // Re-read inside the lock — the caller's model was read before we
            // held the lock and may already be stale (spec §3 idempotency).
            $row = PreAccountBuild::on('pgsql')->find($build->id);
            if ($row === null || $row->invited_at !== null) {
                return null;
            }
            if ($row->user?->site === null) {
                return null;
            }

            // Claim the email send by stamping invited_at now. A first-come
            // build with no contact_email stays un-stamped so a later-added
            // email can still be invited — the DM channel still fans out below.
            if ($row->contact_email !== null && trim($row->contact_email) !== '') {
                $row->forceFill(['invited_at' => now()])->save();
            }

            return $row;
        });

        if ($fresh === null) {
            return;
        }

        // Keep the caller's model in sync with the committed stamp.
        $build->setAttribute('invited_at', $fresh->invited_at);

        $claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$fresh->user->site->subdomain;

        // Queue AFTER the claim commits (Mailable dispatch discipline).
        if ($fresh->contact_email !== null && trim($fresh->contact_email) !== '') {
            Mail::queue(new ClaimInviteMail($fresh->contact_email, $claimUrl));
        }

        // DM channel fans out per spec §3.1 independent of email presence;
        // no-op stub today (deferred seam). NOTE: only the EMAIL send is
        // idempotency-gated (via invited_at) — a no-email build is never
        // stamped, so a repeat notify() re-fires this channel. Harmless while
        // it's a no-op; a real DM driver must add its own send-once guard.
        $this->dm->send($fresh, $claimUrl);
    }
}
