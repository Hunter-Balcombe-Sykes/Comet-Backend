<?php

namespace App\Console\Commands;

use App\Catalog\LegacyPlatformMap;
use Illuminate\Console\Command;

/**
 * Renders the `platform` alias CASE from the compiled catalog.
 *
 * The legacy slug vocabulary is authored in the catalog (Surface's
 * `legacy_platform`), but Postgres cannot read bootstrap/catalog/compiled.php —
 * site.platform_connections.platform is a GENERATED ALWAYS ... STORED column
 * whose CASE has to be spelled out in SQL. That is why the two cannot simply be
 * one lane; this command is how the SQL lane is kept a projection of the
 * catalog rather than a second hand-maintained table.
 *
 * Changing a slug is a MIGRATION, and an expensive one — STORED means a full
 * heap rewrite under ACCESS EXCLUSIVE. Emit the CASE here, paste it into a new
 * migration, and let CatalogLegacyMapTest prove the two agree.
 *
 * --historical adds the retired partna.* arms, matching what the APPLIED
 * migration contains: an applied migration records what ran, and those arms
 * alias harmlessly with zero rows to alias.
 */
class CatalogEmitLegacyAliasSqlCommand extends Command
{
    protected $signature = 'catalog:emit-legacy-alias-sql
        {--historical : include the retired partna.* arms, as the applied migration has them}';

    protected $description = 'Render the platform alias CASE from the compiled catalog';

    public function handle(): int
    {
        $map = $this->option('historical')
            ? LegacyPlatformMap::historicalSpecialToLegacyMap()
            : LegacyPlatformMap::specialToLegacyMap();

        ksort($map);

        $this->line('CASE "surface_key"');

        foreach ($map as $surfaceKey => $legacy) {
            $this->line(sprintf("    WHEN '%s' THEN '%s'", $surfaceKey, $legacy));
        }

        $this->line("    ELSE split_part(\"surface_key\", '.', 1)");
        $this->line('END');

        return self::SUCCESS;
    }
}
