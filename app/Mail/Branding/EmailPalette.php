<?php

namespace App\Mail\Branding;

// Email-safe subset of the design kit (colors + border radius). Every field is
// non-null: defaults/derivation are pre-applied by EmailBrandDefaults before
// construction, so templates never have to handle a missing token. Fonts are
// deliberately excluded — email clients fall back to system fonts regardless.
final class EmailPalette
{
    public function __construct(
        public readonly string $accent,
        public readonly string $accentContrast,
        public readonly string $bg,
        public readonly string $text,
        public readonly string $textMuted,
        public readonly string $buttonBg,
        public readonly string $buttonText,
        public readonly string $borderRadius,
    ) {}
}
