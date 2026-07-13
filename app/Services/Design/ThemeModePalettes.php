<?php

namespace App\Services\Design;

// Default-variant anchors of the theme-mode palettes. LOCKSTEP mirror of
// partna-monorepo/packages/design-system/src/design-kit/palettes.ts — email
// theming needs bg/text server-side and cannot import the TS package.
class ThemeModePalettes
{
    public const DEFAULT_MODE = 'bleach';

    /** @var array<string, array{bg: string, text: string}> */
    public const ANCHORS = [
        // ALD-calibrated default-variant bg/text (2026-07-14). Every mode's ink
        // is now a legible near-black / near-white, so the mirror tracks the
        // sitepage value directly — no more per-mode legibility override.
        'bleach' => ['bg' => '#ffffff', 'text' => '#181818'],
        'dust' => ['bg' => '#faf9f6', 'text' => '#1c1a17'],
        'warm' => ['bg' => '#f9f3ec', 'text' => '#211b13'],
        'dusk' => ['bg' => '#262626', 'text' => '#f2f2f2'],
        'midnight' => ['bg' => '#181818', 'text' => '#ffffff'],
    ];

    /** @return array{bg: string, text: string} */
    public static function anchorsFor(?string $mode): array
    {
        // Coalesce before indexing: a null offset is a PHP 8.5 deprecation.
        return self::ANCHORS[$mode ?? self::DEFAULT_MODE] ?? self::ANCHORS[self::DEFAULT_MODE];
    }
}
