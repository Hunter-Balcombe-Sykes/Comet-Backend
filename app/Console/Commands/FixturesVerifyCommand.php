<?php

namespace App\Console\Commands;

use App\Support\Fixtures\FixtureManifest;
use Illuminate\Console\Command;

/** Re-hash every recorded fixture against MANIFEST.json; list orphans and gaps. Exit 1 on any problem. */
class FixturesVerifyCommand extends Command
{
    protected $signature = 'fixtures:verify {--root= : corpus root (default tests/fixtures/recorded)}';

    protected $description = 'Verify tests/fixtures/recorded/ against MANIFEST.json (hashes, orphans, missing files).';

    public function handle(): int
    {
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));
        $problems = (new FixtureManifest($root.'/MANIFEST.json'))->verify($root);

        if ($problems === []) {
            $this->info('Recorded corpus matches MANIFEST.json.');

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->line($p);
        }
        $this->error(count($problems).' problem(s).');

        return self::FAILURE;
    }
}
