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
        $site = $build->user?->site;
        if ($site === null) {
            return;
        }

        $claimUrl = rtrim((string) config('app.frontend_url'), '/').'/claim/'.$site->subdomain;

        if ($build->contact_email !== null && trim($build->contact_email) !== '') {
            Mail::queue(new ClaimInviteMail($build->contact_email, $claimUrl));
        }

        // DM channel: no-op stub today (spec §3.1 deferred seam).
        $this->dm->send($build, $claimUrl);
    }
}
