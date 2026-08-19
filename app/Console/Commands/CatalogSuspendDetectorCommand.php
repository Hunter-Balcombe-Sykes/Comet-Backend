<?php

namespace App\Console\Commands;

use App\Catalog\CompiledCatalog;
use App\Catalog\DetectorSuspensions;
use Illuminate\Console\Command;

/**
 * The operator control for catalog.detector_suspensions — take one detector
 * out of the running without a recompile or a deploy.
 *
 * Reach for this when a rule is actively placing the wrong brand and the fix
 * (edit the definition, recompile, ship) is slower than the damage. It is
 * explicitly a stopgap: the window is bounded and the suspension never reaches
 * the compiled artefact, so the real fix still has to happen.
 *
 * Artisan rather than a staff endpoint, deliberately — see the class docblock
 * on DetectorSuspensions.
 */
class CatalogSuspendDetectorCommand extends Command
{
    protected $signature = 'catalog:suspend-detector
        {detector? : the detector id, exactly as it appears in the compiled catalog}
        {--reason= : why, for whoever reads this next}
        {--hours=24 : how long the suspension runs}
        {--by= : who is doing this, e.g. staff:josh}
        {--release : lift an existing suspension instead of setting one}
        {--list : show the live suspensions and exit}';

    protected $description = 'Suspend, release or list catalog detector kill-switches';

    /**
     * Ceiling on a single suspension. A kill-switch that can be set for a year
     * is a fork of the catalog nobody remembers making; re-running the command
     * to extend is cheap and leaves a fresh set_at behind.
     */
    private const MAX_HOURS = 720;

    public function handle(DetectorSuspensions $suspensions): int
    {
        if ($this->option('list')) {
            return $this->list($suspensions);
        }

        $detector = (string) $this->argument('detector');
        if ($detector === '') {
            $this->error('Which detector? Pass an id, or --list to see the live suspensions.');

            return self::FAILURE;
        }

        if ($this->option('release')) {
            if (! $suspensions->release($detector)) {
                // Not a no-op success: during an incident, "released" and
                // "there was nothing to release" must not read the same.
                $this->error("No live suspension for {$detector}.");

                return self::FAILURE;
            }

            $this->info("Released {$detector} — it will match again on the next request.");

            return self::SUCCESS;
        }

        // Validated against the compiled catalog because the failure mode is
        // silent: a mistyped id writes a perfectly valid row that suspends
        // nothing, and the operator walks away believing the detector is off.
        if (! array_key_exists($detector, CompiledCatalog::detectors())) {
            $this->error("Unknown detector: {$detector}. It must appear in the compiled catalog.");

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required — an unexplained suspension cannot be reviewed.');

            return self::FAILURE;
        }

        $hours = (int) $this->option('hours');
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            $this->error('--hours must be between 1 and '.self::MAX_HOURS.'. Re-run the command to extend.');

            return self::FAILURE;
        }

        $expiresAt = now()->addHours($hours);
        $suspensions->suspend($detector, $reason, $this->option('by') ?: null, $expiresAt);

        $this->info("Suspended {$detector} until {$expiresAt->toDateTimeString()} UTC.");
        $this->line('Links it would have placed now fall through to the next-best rule, or become plain notes.');

        return self::SUCCESS;
    }

    private function list(DetectorSuspensions $suspensions): int
    {
        $rows = $suspensions->listActive();

        if ($rows === []) {
            $this->info('No live detector suspensions.');

            return self::SUCCESS;
        }

        $this->table(
            ['detector', 'reason', 'set by', 'expires'],
            array_map(fn ($row) => [
                $row->detector_id,
                $row->reason,
                $row->set_by ?? '—',
                $row->expires_at,
            ], $rows),
        );

        return self::SUCCESS;
    }
}
