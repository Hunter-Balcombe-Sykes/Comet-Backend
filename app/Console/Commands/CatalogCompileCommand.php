<?php

namespace App\Console\Commands;

use App\Catalog\Brand;
use App\Catalog\CapabilityManifest;
use App\Catalog\Hosts;
use App\Catalog\Surface;
use App\Routing\Rulepack;
use Illuminate\Console\Command;

/**
 * Compiles the four-plane catalog (app/Catalog/Definitions) into the single
 * content-addressed artefact the router and runtime read:
 * bootstrap/catalog/compiled.php. Every SurfaceContract asserts, every
 * capability name must exist in the manifest, keys and detector ids must be
 * unique — a definition that can't pass doesn't compile, so a bad surface is
 * unshippable rather than discovered in production.
 */
class CatalogCompileCommand extends Command
{
    protected $signature = 'catalog:compile {--check : verify the committed artefact matches the definitions (CI guard, writes nothing)}';

    protected $description = 'Compile app/Catalog/Definitions into bootstrap/catalog/compiled.php';

    public function handle(): int
    {
        $definitionClasses = require app_path('Catalog/Definitions/_manifest.php');

        $brands = [];
        $surfaces = [];
        $detectors = [];
        $errors = [];

        foreach ($definitionClasses as $class) {
            if (! class_exists($class)) {
                $errors[] = "manifest lists missing class {$class}";

                continue;
            }

            /** @var Brand $brand */
            $brand = $class::brand();
            if (isset($brands[$brand->key])) {
                $errors[] = "duplicate brand key {$brand->key} ({$class})";
            }
            $brands[$brand->key] = [
                'key' => $brand->key,
                'display_name' => $brand->displayName,
                'homepage' => $brand->homepage,
                'lifecycle' => $brand->lifecycle->value,
                'successor_key' => $brand->successorKey,
            ];

            /** @var Surface $surface */
            foreach ($class::surfaces() as $surface) {
                if (isset($surfaces[$surface->key])) {
                    $errors[] = "duplicate surface key {$surface->key} ({$class})";

                    continue;
                }
                if ($surface->brandKey !== $brand->key) {
                    $errors[] = "surface {$surface->key} claims brand {$surface->brandKey} inside {$brand->key}'s definition";
                }

                foreach ($surface->contracts as $contractClass) {
                    try {
                        $contractClass::assert($surface);
                    } catch (\Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }

                foreach ($surface->capabilities as $role => $capabilityName) {
                    if (! CapabilityManifest::has($capabilityName)) {
                        $errors[] = "surface {$surface->key} names unknown capability {$capabilityName} (role {$role})";
                    }
                }

                foreach ($surface->detectors as $detector) {
                    if (isset($detectors[$detector->id])) {
                        $errors[] = "detector id collision {$detector->id} ({$surface->key} vs {$detectors[$detector->id]['surface_key']})";

                        continue;
                    }
                    $detectors[$detector->id] = $detector->toArray();
                }

                $surfaceArray = $surface->toArray();
                // The artefact stores detector IDS on the surface; bodies live
                // in the flat detectors map (dedup + O(1) lookup by id).
                $surfaceArray['detectors'] = array_map(fn ($d) => $d->id, $surface->detectors);
                $surfaces[$surface->key] = $surfaceArray;
            }
        }

        foreach ($brands as $brand) {
            if ($brand['successor_key'] !== null && ! isset($brands[$brand['successor_key']])) {
                $errors[] = "brand {$brand['key']} names missing successor {$brand['successor_key']}";
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error(count($errors).' catalog error(s) — nothing written.');

            return self::FAILURE;
        }

        ksort($brands);
        ksort($surfaces);
        ksort($detectors);
        $aliases = Hosts::aliases();
        ksort($aliases);
        $suffixes = Hosts::suffixOverrides();
        sort($suffixes);

        // Content-addressed: the digest covers the full semantic payload and
        // nothing else (no timestamps), so identical definitions always
        // produce an identical artefact.
        // The routing rulepack is derived from the detectors above and baked
        // in, so the router never builds an index at request time. Its digest
        // input is the same payload, so a rule change always changes the
        // artefact identity.
        $rulepack = Rulepack::derive($detectors, 'pending')->toArtefact();

        $payload = [
            'brands' => $brands,
            'surfaces' => $surfaces,
            'detectors' => $detectors,
            'host_aliases' => $aliases,
            'suffix_overrides' => $suffixes,
            'rulepack' => $rulepack,
        ];
        $digest = 'sha256:'.hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $artefact = "<?php\n\n// GENERATED by `php artisan catalog:compile` — never edit by hand.\nreturn ".var_export(['digest' => $digest] + $payload, true).";\n";

        $path = base_path('bootstrap/catalog/compiled.php');

        if ($this->option('check')) {
            if (! is_file($path)) {
                $this->error('No committed artefact at bootstrap/catalog/compiled.php.');

                return self::FAILURE;
            }
            $existing = require $path;
            if (($existing['digest'] ?? null) !== $digest) {
                $this->error("Artefact digest mismatch — definitions changed without recompiling. Committed: {$existing['digest']}, definitions: {$digest}.");

                return self::FAILURE;
            }
            $this->info("Artefact matches definitions ({$digest}).");

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $tmp = $path.'.tmp';
        file_put_contents($tmp, $artefact);
        rename($tmp, $path);

        $this->info(sprintf(
            'Compiled %d brand(s), %d surface(s), %d detector(s) → %s',
            count($brands),
            count($surfaces),
            count($detectors),
            $digest,
        ));

        return self::SUCCESS;
    }
}
