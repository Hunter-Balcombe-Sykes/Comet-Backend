<?php

namespace App\Services\Design;

// Default-variant anchors of the theme-mode palettes. LOCKSTEP mirror of
// partna-monorepo/packages/design-system/src/design-kit/palettes.ts — email
// theming needs bg/text server-side and cannot import the TS package.
//
// ONE palette since 2026-08-06 (design-kit simplification). The roster used to
// hold five here (bleach, dust, warm, dusk, midnight) and three on the sitepage
// side (bleach, dust, tonal) — the two never agreed, so 'warm', 'dusk' and
// 'midnight' were modes only the backend believed in: the renderer rejected
// them as unknown and drew bleach. Collapsing to bleach closes that gap rather
// than widening it.
//
// The shape is deliberately kept as a keyed roster rather than flattened to two
// constants: a second palette is a product decision away, and anchorsFor()'s
// null-coalescing fallback is what keeps a stale row rendering instead of
// fataling.
class ThemeModePalettes
{
    public const DEFAULT_MODE = 'bleach';

    /** @var array<string, array{bg: string, text: string}> */
    public const ANCHORS = [
        // ALD-calibrated default-variant bg/text (2026-07-14). The ink is a
        // legible near-black, so the mirror tracks the sitepage value directly.
        'bleach' => ['bg' => '#ffffff', 'text' => '#181818'],
    ];

    /** @return array{bg: string, text: string} */
    public static function anchorsFor(?string $mode): array
    {
        // Coalesce before indexing: a null offset is a PHP 8.5 deprecation.
        return self::ANCHORS[$mode ?? self::DEFAULT_MODE] ?? self::ANCHORS[self::DEFAULT_MODE];
    }
}
