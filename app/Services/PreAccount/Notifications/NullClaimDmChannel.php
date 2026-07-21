<?php

namespace App\Services\PreAccount\Notifications;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Log;

// No-op DM driver until the real integration ships. Logs so we can confirm the
// dispatch point fires in prod without sending anything.
class NullClaimDmChannel implements ClaimDmChannel
{
    public function send(PreAccountBuild $build, string $claimUrl): void
    {
        Log::info('claim.dm.stub', ['build_id' => $build->id, 'source_type' => $build->source_type]);
    }
}
