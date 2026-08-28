<?php

use App\Services\PreAccount\Generators\SiteSourceGenerator;
use App\Services\Profile\ProfileEnricher;

// BioIntelligence shipped wired into ONE source (Instagram), so the entire
// quality bar it exists to provide — auto-About, contact extraction, mention
// chains — reached one of the two account types and no business account ever
// saw it. Nothing in the contract said otherwise: SiteSourceGenerator is an
// interface, and an interface cannot make a method get CALLED.
//
// This is that missing half, in the style of PoolCacheLaneSeamTest: a cheap
// static guard over the CONFIGURED registry, so it grows automatically when a
// third source is added. It keeps CLAUDE.md's story true — a new source is
// still one generator class + one config entry + one source_type CHECK
// migration — while making "and it goes through enrichment" non-optional.
//
// What this does NOT catch: a generator that injects the enricher and calls
// enrich() with a deliberately empty BioSource. The guard proves the seam is
// wired, not that the mapping is good.

/** @return array<string, class-string<SiteSourceGenerator>> */
function registeredGenerators(): array
{
    return config('partna.pre_account.generators');
}

it('resolves every configured pre-account source to a generator class on disk', function () {
    // Non-vacuity: the assertions below iterate this map, so an empty or
    // mis-keyed registry would let all of them pass by finding nothing.
    $generators = registeredGenerators();

    expect($generators)->toBeArray()->not->toBeEmpty()
        ->and(array_keys($generators))->toContain('instagram', 'google_business');

    foreach ($generators as $sourceType => $class) {
        expect(class_exists($class))->toBeTrue("Generator for '{$sourceType}' does not exist: {$class}")
            ->and(is_a($class, SiteSourceGenerator::class, true))->toBeTrue("{$class} must implement SiteSourceGenerator");
    }
});

it('requires every registered generator to depend on the shared ProfileEnricher', function () {
    foreach (registeredGenerators() as $sourceType => $class) {
        $params = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        $injectsEnricher = collect($params)->contains(
            fn (ReflectionParameter $p) => ($p->getType() instanceof ReflectionNamedType)
                && $p->getType()->getName() === ProfileEnricher::class
        );

        expect($injectsEnricher)->toBeTrue(
            "Pre-account source '{$sourceType}' ({$class}) must take ProfileEnricher as a constructor "
            .'dependency — every source runs the same enrichment step.'
        );
    }
});

it('requires every registered generator to actually call the enrichment seam', function () {
    foreach (registeredGenerators() as $sourceType => $class) {
        $file = (new ReflectionClass($class))->getFileName();
        $source = (string) file_get_contents($file);

        // str_contains, not toContain: Pest's toContain is VARIADIC, so a second
        // argument is read as another NEEDLE, never as a failure message — the
        // assertion silently becomes "contains this explanation too" and fails.
        expect(str_contains($source, '->enrich('))->toBeTrue(
            "Pre-account source '{$sourceType}' ({$class}) injects ProfileEnricher but never calls "
            .'enrich() — the dependency alone enriches nothing.'
        );
    }
});

it('keeps BioIntelligence out of the generators, so the seam is the only route to the model', function () {
    foreach (registeredGenerators() as $sourceType => $class) {
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

        expect(str_contains($source, 'BioIntelligence'))->toBeFalse(
            "Pre-account source '{$sourceType}' ({$class}) reaches BioIntelligence directly. Go through "
            .'ProfileEnricher: it is what stops the same paid analyse() running twice in one build.'
        );
    }
});
