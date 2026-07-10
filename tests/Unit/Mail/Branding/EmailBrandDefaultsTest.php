<?php

use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;

it('returns the static defaults when the kit is empty', function () {
    $p = EmailBrandDefaults::palette([]);

    // bg/text are the bleach (default theme-mode) palette anchors since the
    // 2026-07-10 rework — color_bg/color_text no longer feed the email palette.
    expect($p)->toBeInstanceOf(EmailPalette::class)
        ->and($p->accent)->toBe('#3a6efc')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#111113')
        ->and($p->textMuted)->toBe('#6e6e73')
        ->and($p->borderRadius)->toBe('8px');
});

it('derives button tokens from accent/accent-contrast when the kit leaves them null', function () {
    $p = EmailBrandDefaults::palette([
        'color_accent' => '#aa0000',
        'color_accent_contrast' => '#ffeeee',
        // button_primary_bg / button_primary_text intentionally absent (NULL columns)
    ]);

    expect($p->buttonBg)->toBe('#aa0000')        // derived from accent
        ->and($p->buttonText)->toBe('#ffeeee');  // derived from accentContrast
});

it('prefers stored values over defaults and over derivation', function () {
    $p = EmailBrandDefaults::palette([
        'color_accent' => '#aa0000',
        'theme_mode' => 'midnight',   // bg comes from the mode's palette anchors
        'button_primary_bg' => '#00ff00',
        'button_primary_text' => '#0000ff',
        'border_radius' => '2px',
    ]);

    expect($p->accent)->toBe('#aa0000')
        ->and($p->bg)->toBe('#000000')           // midnight default-variant anchor
        ->and($p->buttonBg)->toBe('#00ff00')     // stored wins over derived accent
        ->and($p->buttonText)->toBe('#0000ff')
        ->and($p->borderRadius)->toBe('2px');
});

it('takes bg and text from the theme-mode palette anchors', function () {
    $p = EmailBrandDefaults::palette(['theme_mode' => 'dusk']);

    expect($p->bg)->toBe('#26262c')
        ->and($p->text)->toBe('#e8e8ec');
});

it('falls back to bleach anchors for an unknown theme_mode', function () {
    $p = EmailBrandDefaults::palette(['theme_mode' => 'neon']);

    expect($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#111113');
});

it('ignores empty-string stored values and falls back', function () {
    $p = EmailBrandDefaults::palette(['color_accent' => '']);
    expect($p->accent)->toBe('#3a6efc');
});
