<?php

namespace App\Services\PreAccount;

use App\Mail\PreAccount\ClaimInviteMail;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\Notifications\ClaimDmChannel;
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
        // Idempotency: a job retry or a re-publish must not re-send (spec §3).
        if ($build->invited_at !== null) {
            return;
        }

        $site = $build->user?->site;
        if ($site === null) {
            return;
        }

        $claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$site->subdomain;

        $sent = false;
        if ($build->contact_email !== null && trim($build->contact_email) !== '') {
            Mail::queue(new ClaimInviteMail($build->contact_email, $claimUrl));
            $sent = true;
        }

        // DM channel: no-op stub today (spec §3.1 deferred seam).
        $this->dm->send($build, $claimUrl);

        // Stamp only when an email actually went out, and AFTER queueing so a
        // transport failure leaves the build re-sendable. A first-come build
        // (no contact_email) stays un-stamped so a later-added email can still
        // be invited via POST /builds/{build}/invite.
        if ($sent) {
            $build->forceFill(['invited_at' => now()])->save();
        }
    }
}
