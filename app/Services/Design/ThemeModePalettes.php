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
        'bleach' => ['bg' => '#ffffff', 'text' => '#111113'],
        // dust: the sitepage ink (#999490) is deliberately soft/low-contrast;
        // email body text must stay legible, so the mirror pins the previous
        // dark ink instead of tracking the sitepage value.
        'dust' => ['bg' => '#f2f2f0', 'text' => '#1e1e21'],
        'warm' => ['bg' => '#faf4f0', 'text' => '#111111'],
        'dusk' => ['bg' => '#26262c', 'text' => '#e8e8ec'],
        'midnight' => ['bg' => '#000000', 'text' => '#f2f2f2'],
    ];

    /** @return array{bg: string, text: string} */
    public static function anchorsFor(?string $mode): array
    {
        // Coalesce before indexing: a null offset is a PHP 8.5 deprecation.
        return self::ANCHORS[$mode ?? self::DEFAULT_MODE] ?? self::ANCHORS[self::DEFAULT_MODE];
    }
}
