<?php

namespace Tests\Support\Architecture;

/**
 * Denominator guard for coverage sweeps (COV-GUARD-4 / COV-GUARD-5).
 *
 * WHY: every sweep in this suite asserts "the missing-list is empty". That is
 * also true when the sweep examined NOTHING — a PSR-4 move, a renamed
 * middleware alias or a changed Finder glob zeroes the input set and the test
 * stays green having proven nothing. This asserts the input set instead.
 */
final class SweepGuard
{
    /** @param array<mixed> $set */
    public static function assertDenominator(array $set, int $floor, string $what): void
    {
        expect($set)->not->toBeEmpty(
            "{$what}: the sweep examined ZERO items. The guard below cannot fail in this state — "
            .'discovery is broken (moved namespace, changed path, renamed alias), not clean.'
        );

        expect(count($set))->toBeGreaterThanOrEqual(
            $floor,
            "{$what}: only ".count($set)." item(s) discovered, floor is {$floor}. "
            .'Either discovery has partially broken, or a real consolidation happened — '
            .'if the latter, lower the floor IN THE SAME COMMIT as the deletion and say why.'
        );
    }
}
