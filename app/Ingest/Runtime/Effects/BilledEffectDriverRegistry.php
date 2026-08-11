<?php

namespace App\Ingest\Runtime\Effects;

/**
 * (kind, name) -> driver. Matching on BOTH halves is deliberate: `actor` is
 * shared by Instagram and the three menu connectors, which have different
 * vendors, different budgets and different result shapes. A kind-only registry
 * would hand a menu scrape to the Instagram driver.
 *
 * Null for an unmatched pair is not an error here — HttpIo turns it into the
 * same throw it has always raised, which is what keeps an undeclared billed
 * effect loud instead of silently free.
 */
final class BilledEffectDriverRegistry
{
    /** @param iterable<BilledEffectDriver> $drivers */
    public function __construct(private readonly iterable $drivers = []) {}

    public function for(string $kind, string $name): ?BilledEffectDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($kind, $name)) {
                return $driver;
            }
        }

        return null;
    }
}
