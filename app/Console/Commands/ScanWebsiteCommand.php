<?php

namespace App\Console\Commands;

use App\Services\Design\WebsiteStyleAnalyzer;
use Illuminate\Console\Command;

/**
 * Live brand-scan of a URL with the full evidence trail — the debugging answer
 * to "it read something weird from this site". Read-only: nothing is stored.
 */
class ScanWebsiteCommand extends Command
{
    protected $signature = 'design:scan-website {url : The website to scan} {--json : Emit the raw analysis document}';

    protected $description = 'Run the brand scanner against a URL and print per-signal conclusions, confidence, and evidence';

    public function handle(WebsiteStyleAnalyzer $analyzer): int
    {
        $url = (string) $this->argument('url');
        $started = microtime(true);
        $analysis = $analyzer->analyze($url);
        $elapsed = round(microtime(true) - $started, 1);

        if ($this->option('json')) {
            $this->line((string) json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $analysis['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Scanned {$url} in {$elapsed}s — mode={$analysis['mode']} ok=".var_export($analysis['ok'], true)
            .($analysis['failure'] ? " failure={$analysis['failure']}" : ''));

        $rows = [];
        foreach ($analysis['signals'] as $signal => $data) {
            $rows[] = [$signal, $data['tier'], number_format($data['confidence'], 2), implode(' · ', $data['evidence'] ?? [])];
        }
        if ($rows !== []) {
            $this->table(['signal', 'tier', 'confidence', 'evidence'], $rows);
        } else {
            $this->warn('No confident signals.');
        }

        $accent = $analysis['accent'];
        $this->line($accent
            ? "accent: {$accent['hex']} @".number_format($accent['confidence'], 2).' ('.implode(' · ', $accent['evidence'] ?? []).')'
            : 'accent: none (no intentional evidence — correct for monochrome brands)');

        $candidates = $analysis['logo']['candidates'] ?? [];
        $this->line('logo candidates: '.count($candidates));
        foreach (array_slice($candidates, 0, 8) as $c) {
            $desc = $c['kind'].' '.($c['url'] ?? ($c['viewBox'] ?? 'inline'));
            $extra = array_filter([
                isset($c['natW'], $c['natH']) && $c['natW'] ? "{$c['natW']}x{$c['natH']}" : null,
                ($c['sizes'] ?? null) ? "sizes={$c['sizes']}" : null,
                ($c['hint'] ?? false) ? 'hinted' : null,
                ($c['inHeader'] ?? false) ? 'in-header' : null,
            ]);
            $this->line('  - '.$desc.($extra !== [] ? '  ['.implode(', ', $extra).']' : ''));
        }

        foreach ($analysis['notes'] as $note) {
            $this->comment('note: '.$note);
        }

        return $analysis['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
