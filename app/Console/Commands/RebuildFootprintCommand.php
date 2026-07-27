<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Measures the rebuild's actual footprint (plan §18/§19) instead of
 * re-estimating it.
 *
 * The plan's numbers were arrived at by counting files by hand and were
 * corrected once already when the arithmetic did not sum. This command reads
 * the tree, so the figure in the final report is a measurement with a method
 * anyone can re-run — and so the P8 exit criterion ("re-measure") is a command
 * rather than an exercise in recounting.
 */
class RebuildFootprintCommand extends Command
{
    protected $signature = 'rebuild:footprint {--json}';

    protected $description = 'Measure LOC added by the rebuild and still-live LOC it is scheduled to replace';

    /** What the rebuild ADDED. */
    private const NEW_PATHS = [
        'catalog (planes + definitions + compiler)' => ['app/Catalog', 'app/Console/Commands/CatalogCompileCommand.php', 'app/Console/Commands/CatalogSyncCommand.php'],
        'routing subsystem' => ['app/Routing', 'app/Http/Controllers/Api/Routing', 'app/Console/Commands/RoutingCorpusCommand.php', 'app/Console/Commands/RoutingReprojectCommand.php'],
        'ingest runtime + connectors' => ['app/Ingest', 'app/Jobs/Ingest', 'app/Console/Commands/IngestDispatchCommand.php', 'app/Console/Commands/IngestStrandedCommand.php'],
        'content identity + values' => ['app/Content'],
        'sections + documents' => ['app/Site'],
        'schema (new migrations)' => [
            'supabase/migrations/20260727100000_catalog_schema.sql',
            'supabase/migrations/20260727110000_connections_surface_key.sql',
            'supabase/migrations/20260727120000_routing_schema.sql',
            'supabase/migrations/20260727130000_ingest_schema.sql',
            'supabase/migrations/20260727140000_content_schema.sql',
            'supabase/migrations/20260727150000_sections_and_documents.sql',
        ],
    ];

    /**
     * What the rebuild REPLACES — still live, scheduled for deletion at P8
     * once every consumer is off it. Measured so the claim is checkable.
     */
    private const REPLACED_PATHS = [
        'link classification (5 sites)' => [
            'app/Services/Platforms/LinkRouter.php',
            'app/Services/Platforms/ProviderDetector.php',
            'app/Services/Platforms/WebsiteLinkHarvester.php',
            'app/Services/Platforms/LinkInBioDetector.php',
            'app/Services/Platforms/CustomLinkSeeder.php',
        ],
        'platform registry + descriptors' => [
            'app/Providers/PlatformRegistryServiceProvider.php',
            'app/Services/Platforms/Registry',
        ],
        'per-platform services' => ['app/Services/Platforms'],
        'platform jobs' => ['app/Jobs/Platforms'],
    ];

    public function handle(): int
    {
        $added = $this->measure(self::NEW_PATHS);
        $replaced = $this->measure(self::REPLACED_PATHS);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'added' => $added,
                'replaced_still_live' => $replaced,
                'added_total' => array_sum(array_column($added, 'lines')),
                'replaced_total' => array_sum(array_column($replaced, 'lines')),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('ADDED by the rebuild');
        $this->table(['Area', 'Files', 'Lines'], array_map(
            fn (string $label, array $row) => [$label, $row['files'], number_format($row['lines'])],
            array_keys($added),
            $added,
        ));

        $this->newLine();
        $this->info('REPLACED — still live, scheduled for P8 deletion');
        $this->table(['Area', 'Files', 'Lines'], array_map(
            fn (string $label, array $row) => [$label, $row['files'], number_format($row['lines'])],
            array_keys($replaced),
            $replaced,
        ));

        $addedTotal = array_sum(array_column($added, 'lines'));
        $replacedTotal = array_sum(array_column($replaced, 'lines'));

        $this->newLine();
        $this->line(sprintf('Added:    %s lines', number_format($addedTotal)));
        $this->line(sprintf('Replaced: %s lines (backend only — FE mirror and design-system engines counted separately)', number_format($replacedTotal)));
        $this->line(sprintf('Net:      %s lines', number_format($addedTotal - $replacedTotal)));
        $this->newLine();
        $this->comment('Backend PHP only. The plan\'s §18/§19 figures also span the frontend and monorepo,');
        $this->comment('which this command does not read — it measures what it can actually see.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<string>>  $groups
     * @return array<string, array{files: int, lines: int}>
     */
    private function measure(array $groups): array
    {
        $out = [];

        foreach ($groups as $label => $paths) {
            $files = 0;
            $lines = 0;

            foreach ($paths as $path) {
                $absolute = base_path($path);

                if (is_file($absolute)) {
                    $files++;
                    $lines += $this->countLines($absolute);

                    continue;
                }

                if (! is_dir($absolute)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile() && in_array($file->getExtension(), ['php', 'sql'], true)) {
                        $files++;
                        $lines += $this->countLines($file->getPathname());
                    }
                }
            }

            $out[$label] = ['files' => $files, 'lines' => $lines];
        }

        return $out;
    }

    private function countLines(string $path): int
    {
        $contents = file_get_contents($path);

        return $contents === false ? 0 : substr_count($contents, "\n") + 1;
    }
}
