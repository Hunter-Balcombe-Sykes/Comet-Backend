<?php

namespace App\Console\Commands\Moderation;

use App\Models\Moderation\ModerationCase;
use Illuminate\Console\Command;

/**
 * Support utility: print a full case snapshot as JSON to stdout.
 * Includes signals, evidence, and decisions.
 * Used by support staff to quickly inspect a case in the CLI.
 */
class ModerationShowCaseCommand extends Command
{
    protected $signature = 'moderation:show-case {case_id}';

    protected $description = 'Print a case + signals + evidence + decisions as JSON (support utility).';

    public function handle(): int
    {
        $case = ModerationCase::query()
            ->with(['signals', 'evidence', 'decisions'])
            ->find($this->argument('case_id'));

        if ($case === null) {
            $this->error('Case not found.');

            return self::FAILURE;
        }

        $this->line(json_encode([
            'case_id' => $case->id,
            'case' => $case->toArray(),
            'signals' => $case->signals->map->toArray()->values(),
            'evidence' => $case->evidence->map->toArray()->values(),
            'decisions' => $case->decisions->map->toArray()->values(),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
