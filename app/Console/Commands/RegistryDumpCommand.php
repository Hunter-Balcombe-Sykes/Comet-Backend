<?php

namespace App\Console\Commands;

use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Console\Command;

/**
 * The PD-retirement plan's registry-diff harness (P0, 2026-08-27): dump every
 * descriptor's OBSERVABLE fields, sorted and stable, so a provider/factory
 * change can be proven byte-identical for untouched platforms:
 *
 *   php artisan registry:dump > /tmp/before.json
 *   …make the change…
 *   php artisan registry:dump > /tmp/after.json && diff /tmp/{before,after}.json
 *
 * Every phase gate of the retirement runs this diff and must show ONLY the
 * intended changes.
 */
class RegistryDumpCommand extends Command
{
    protected $signature = 'registry:dump';

    protected $description = 'Dump every platform descriptor\'s observable fields as stable JSON (PD-retirement diff harness)';

    public function handle(PlatformRegistry $registry): int
    {
        $out = [];
        foreach ($registry->all() as $slug => $d) {
            $out[$slug] = [
                'label' => $d->getLabel(),
                'category' => $d->getCategory()?->value,
                'derived' => $d->isDerived(),
                'surface_key' => $d->getSurfaceKey(),
                'route_shape' => $d->routeShape()->name,
                'connect_field' => $d->connectField(),
                'connect_error' => $d->connectErrorMessage(),
                'payload' => $d->payloadClass(),
                'resource' => $d->resourceClass(),
                'refreshable' => $d->isRefreshable(),
                'multi_account' => $d->multiAccount(),
                'has_detect' => $d->detection() !== null,
                'has_connect_strategy' => $d->connectStrategy() !== null,
                'has_fetch' => $d->refreshStrategy() !== null,
                'has_completeness' => $d->hasCompletenessPredicate(),
                'display_toggles' => array_keys($d->displayToggleDefs()),
            ];
        }
        ksort($out);

        $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
