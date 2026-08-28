<?php

namespace App\Console\Commands;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Console\Command;

// Run tooling, third of the fleet trio (fleet:rebuild tears an EXISTING
// account down and rebuilds it; this one makes accounts that never existed).
// The 2026-08-28 overnight round needs fresh cold builds by the dozen, and
// each one through `cloud tinker` is a JSON-envelope round trip whose PHP
// literal has to survive two layers of shell quoting — the exact cost the
// efficiency protocol's item 2 was written about.
//
// Specs arrive base64-encoded on purpose: business names carry apostrophes,
// ampersands and accents ("Mario's Bar & Grill"), and every one of those is a
// quoting landmine across zsh -> cloud CLI -> artisan. Base64 has no
// metacharacters, so one encoding rule replaces the whole escaping matrix.
//
// All-or-nothing validation, matching fleet:rebuild: the entire batch is
// checked before ANY build is requested, because a half-built batch costs a
// prune cycle to clean up.
class FleetNewCommand extends Command
{
    protected $signature = 'fleet:new
        {--b64= : base64 of a JSON list of {account_type, source_type, source_ref, source_name}}
        {--json= : the same JSON inline (quoting-fragile; prefer --b64)}
        {--dry-run : Validate + print the specs, change nothing}';

    protected $description = 'Cold-build unclaimed accounts from real sources (staff path, bypasses the per-IP cap).';

    /** Source types that have a generator; anything else is a typo, not a feature. */
    private const SOURCE_TYPES = ['instagram', 'google_business'];

    private const ACCOUNT_TYPES = ['partna', 'business'];

    public function handle(PreAccountBuildService $builds): int
    {
        $raw = $this->option('b64') !== null && $this->option('b64') !== ''
            ? base64_decode((string) $this->option('b64'), true)
            : (string) $this->option('json');

        if (! is_string($raw) || trim($raw) === '') {
            $this->error('Pass --b64= (preferred) or --json= with a JSON list of specs.');

            return self::FAILURE;
        }

        $specs = json_decode($raw, true);
        if (! is_array($specs) || $specs === []) {
            $this->error('Specs did not decode to a non-empty JSON list.');

            return self::FAILURE;
        }

        $clean = [];
        foreach ($specs as $i => $spec) {
            if (! is_array($spec)) {
                $this->error("spec #{$i}: not an object.");

                return self::FAILURE;
            }

            $accountType = strtolower(trim((string) ($spec['account_type'] ?? '')));
            $sourceType = strtolower(trim((string) ($spec['source_type'] ?? '')));
            $sourceRef = trim((string) ($spec['source_ref'] ?? ''));
            $sourceName = trim((string) ($spec['source_name'] ?? ''));

            if (! in_array($accountType, self::ACCOUNT_TYPES, true)) {
                $this->error("spec #{$i}: account_type '{$accountType}' is not one of ".implode('/', self::ACCOUNT_TYPES).'.');

                return self::FAILURE;
            }
            if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
                $this->error("spec #{$i}: source_type '{$sourceType}' is not one of ".implode('/', self::SOURCE_TYPES).'.');

                return self::FAILURE;
            }
            if ($sourceRef === '') {
                $this->error("spec #{$i}: source_ref is empty.");

                return self::FAILURE;
            }
            // A google_business build seeds its handle from the NAME (the
            // place_id is opaque), so an empty name there silently allocates
            // "business", "business1", "business2"… — two of those are already
            // in the dev fleet from exactly this mistake.
            if ($sourceName === '' && $sourceType === 'google_business') {
                $this->error("spec #{$i}: google_business needs a source_name — the place id cannot seed a handle.");

                return self::FAILURE;
            }

            $clean[] = compact('accountType', 'sourceType', 'sourceRef', 'sourceName');
        }

        foreach ($clean as $spec) {
            $this->line("{$spec['accountType']} {$spec['sourceType']} '{$spec['sourceName']}' ref={$spec['sourceRef']}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        $staff = PartnaStaff::query()->first();
        if (! $staff) {
            $this->error('No PartnaStaff row — the staff build path needs one.');

            return self::FAILURE;
        }

        $failures = 0;
        foreach ($clean as $spec) {
            try {
                $result = $builds->requestBuild(
                    $spec['accountType'],
                    $spec['sourceType'],
                    $spec['sourceRef'],
                    $spec['sourceName'] !== '' ? $spec['sourceName'] : null,
                    null,
                    $staff,
                );
                $build = $result['build'];
                $handle = User::query()->whereKey($build->user_id)->value('handle_lc');
                $this->info("{$spec['sourceRef']} -> build {$build->id} (handle {$handle})");
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$spec['sourceRef']}: build failed — ".$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
