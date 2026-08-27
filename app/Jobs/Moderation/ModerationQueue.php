<?php

namespace App\Jobs\Moderation;

/**
 * The one name for the high-priority moderation lane, shared by the five
 * jobs that ride it and the three config/horizon.php sites that consume
 * it (queue list, wait-time key, timeout key). A FIXED contract, not an
 * env knob, on purpose: config/partna.php used to declare an env-tunable
 * `moderation.queue.high_priority_lane` that nothing read (plan 05 pass
 * 3, 2026-08-27), and making it real would have been a foot-gun — the
 * app cluster and Horizon load config independently, so an env flip that
 * reaches one before the other strands suspend/quarantine jobs on a lane
 * no supervisor consumes. Renaming the lane is a coordinated change to
 * this constant plus a redeploy of both clusters.
 */
final class ModerationQueue
{
    public const HIGH = 'moderation_high';
}
