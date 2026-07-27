<?php

namespace App\Catalog;

/**
 * Boot handshake between the compiled artefact and the running binary.
 * An UNSERVABLE surface (names a capability this build's manifest lacks) is
 * hidden from pickers and skipped by the refresher — reported, never a 500.
 * An ORPHAN capability (no surface names it) is a deletion candidate.
 * CI treats either as a hard failure (CatalogArtefactTest); production
 * degrades gracefully.
 */
class CatalogIntegrityCheck
{
    /** @return array<string, list<string>> surface key => missing capability names */
    public static function unservableSurfaces(): array
    {
        $unservable = [];
        foreach (CompiledCatalog::surfaces() as $key => $surface) {
            $missing = [];
            foreach ($surface['capabilities'] ?? [] as $name) {
                if (! CapabilityManifest::has($name)) {
                    $missing[] = $name;
                }
            }
            if ($missing !== []) {
                $unservable[$key] = $missing;
            }
        }

        return $unservable;
    }

    /** @return list<string> capability names no surface references */
    public static function orphanCapabilities(): array
    {
        $referenced = [];
        foreach (CompiledCatalog::surfaces() as $surface) {
            foreach ($surface['capabilities'] ?? [] as $name) {
                $referenced[$name] = true;
            }
        }

        return array_values(array_filter(
            array_keys(CapabilityManifest::all()),
            fn (string $name) => ! isset($referenced[$name]),
        ));
    }

    public static function isServable(string $surfaceKey): bool
    {
        return ! array_key_exists($surfaceKey, self::unservableSurfaces());
    }
}
