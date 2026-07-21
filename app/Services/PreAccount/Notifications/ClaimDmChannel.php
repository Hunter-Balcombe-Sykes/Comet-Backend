<?php

namespace App\Services\PreAccount\Notifications;

use App\Models\Core\User\PreAccountBuild;

// Deferred seam (spec §3.1): the "DM the person their claim link" channel.
// The real driver (an open-source ManyChat alternative) implements this later;
// nothing else in the claim/build core changes when it lands.
interface ClaimDmChannel
{
    public function send(PreAccountBuild $build, string $claimUrl): void;
}
