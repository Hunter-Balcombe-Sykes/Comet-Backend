<?php

namespace App\Console\Commands;

use App\Services\Migration\LinkOnlyPayloadNormalizer;
use Illuminate\Console\Command;

/**
 * Phase 1.2 follow-through: normalise twitch/skool/strava connection payloads
 * onto the `{username, url}` shape their new link-only allowlist publishes.
 *
 * Must run in the same deploy window as the demotion. Until it does, those rows
 * publish `username: null` — the link works, the label is blank — because the
 * public resource allowlists keys rather than deriving them.
 *
 * Idempotent (a row already on the target shape is skipped), so re-running is
 * free and is how any row written between deploy and run gets picked up.
 * Read-only under --dry-run.
 */
class BackfillLinkPayloads extends Command
{
    protected $signature = 'platforms:backfill-link-payloads {--dry-run} {--platform= : twitch|skool|strava}';

    protected $description = 'Normalise demoted link-only platform payloads to {username, url}';

    public function handle(LinkOnlyPayloadNormalizer $normalizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $normalizer->run($dryRun, $this->option('platform'));

        $this->line(($dryRun ? '[dry-run] would normalize ' : 'normalized ').$result['normalized']
            .', already normalized '.$result['already_normalized']
            .', unparseable '.$result['unparseable']
            .', skipped (no url) '.$result['skipped_no_url']);

        // Unparseable is a real signal, not noise: it means a stored url no
        // longer satisfies the rule a fresh connect applies, so that row will
        // keep publishing a null username until someone looks at it.
        return $result['unparseable'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
