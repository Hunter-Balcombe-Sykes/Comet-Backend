<?php

// #INH-6 characterisation test. Menu dish matching is keyed on a normalized
// name, and that normalizer was copy-pasted into three classes with comments
// asking future editors to keep them "IDENTICAL". A comment is not a guard:
// if one copy drifts, a suppressed dish stops matching at rebuild time and
// silently reappears on the public menu.
//
// This file pins the OBSERVED output (captured before the consolidation, not
// re-derived from the regexes) and asserts all three call paths agree. It is
// written to pass unchanged both before and after the trait extraction — the
// method name is resolved dynamically because MenuMerger's copy was called
// norm(). That is the point: if this file ever needs editing to stay green,
// the behaviour moved.

use App\Http\Controllers\Api\Platforms\MenuContentController;
use App\Jobs\Platforms\MenuFetchJob;
use App\Services\Platforms\MenuMerger;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * The three classes that key menu matching on a normalized dish name.
 *
 * Invoked via reflection on an un-constructed instance: the normalizer is pure
 * and reads no `$this` state, so skipping the constructor keeps this test free
 * of the DI graph (and of a database) while still binding to the REAL method
 * rather than a re-typed copy of it.
 *
 * @return array<string, callable(string): string>
 */
function inh6MenuNameNormalizers(): array
{
    $bind = function (string $class): callable {
        // MenuMerger's copy was named norm() pre-#INH-6. Resolving the name
        // instead of hardcoding it is what lets this file stay byte-identical
        // across the refactor.
        $name = method_exists($class, 'normalizeName') ? 'normalizeName' : 'norm';
        $method = new ReflectionMethod($class, $name);
        $method->setAccessible(true);
        $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return fn (string $s): string => $method->invoke($instance, $s);
    };

    return [
        'MenuContentController' => $bind(MenuContentController::class),
        'MenuFetchJob' => $bind(MenuFetchJob::class),
        'MenuMerger' => $bind(MenuMerger::class),
    ];
}

/**
 * Observed input => output, captured from the live implementation.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function inh6NormalizationCases(): array
{
    return [
        // Accents are STRIPPED TO NOTHING, not folded — so "Café Latte" and
        // "Cafe Latte" are different dishes. MenuTest.php:1546 depends on this.
        'accent is dropped, not folded' => ['Café Latte', 'caf latte'],
        'unaccented sibling differs' => ['Cafe Latte', 'cafe latte'],
        'uppercase accent lowercases first' => ['CAFÉ LATTE', 'caf latte'],
        'multiple accents each become a gap' => ['Crème Brûlée', 'cr me br l e'],

        // Punctuation runs collapse to a single space, then trim.
        'punctuation run collapses' => ['  Fish   &   Chips!!  ', 'fish chips'],
        'ampersand collapses' => ['Mac & Cheese', 'mac cheese'],
        'em and en dashes collapse' => ['Item—With–Dashes', 'item with dashes'],
        'slash separates digits' => ['Grilled Chicken 1/2', 'grilled chicken 1 2'],

        // Case, whitespace, and the empty-result edges.
        'uppercase lowercases' => ['PIZZA', 'pizza'],
        'surrounding whitespace trims' => ['  Trailing  ', 'trailing'],
        'tabs and newlines are whitespace' => ["Tabs\tand\nNewlines", 'tabs and newlines'],
        'all punctuation normalises to empty' => ['!!!', ''],
        'whitespace only normalises to empty' => ['   ', ''],
        'empty stays empty' => ['', ''],
    ];
}

it('normalizes dish names identically on every call path', function () {
    foreach (inh6MenuNameNormalizers() as $label => $normalize) {
        foreach (inh6NormalizationCases() as $case => [$input, $expected]) {
            expect($normalize($input))->toBe(
                $expected,
                "{$label} changed behaviour for [{$case}]: ".var_export($input, true)
            );
        }
    }
});

it('keeps all three call paths in lockstep', function () {
    $normalizers = inh6MenuNameNormalizers();
    $canonical = array_shift($normalizers);

    // The drift guard the code comments only asked for politely. Inputs beyond
    // the pinned set, so a divergence outside the table above is caught too.
    $probes = ['Café Latte', 'Fish & Chips', 'A—B', '  ', '!!!', 'Item 1/2', "x\ty", 'ÜBER'];

    foreach ($normalizers as $label => $normalize) {
        foreach ($probes as $probe) {
            expect($normalize($probe))->toBe(
                $canonical($probe),
                "{$label} has drifted from the canonical normalizer for: ".var_export($probe, true)
            );
        }
    }
});
