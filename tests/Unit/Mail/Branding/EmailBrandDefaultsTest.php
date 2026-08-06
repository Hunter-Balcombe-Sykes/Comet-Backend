<?php

use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;

it('returns the static defaults when the kit is empty', function () {
    $p = EmailBrandDefaults::palette([]);

    // bg/text are the bleach (default theme-mode) palette anchors since the
    // 2026-07-10 rework — color_bg/color_text no longer feed the email palette.
    expect($p)->toBeInstanceOf(EmailPalette::class)
        ->and($p->accent)->toBe('#1367fb')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#181818')
        ->and($p->textMuted)->toBe('#6e6e73')
        ->and($p->borderRadius)->toBe('0');
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
        'button_primary_bg' => '#00ff00',
        'button_primary_text' => '#0000ff',
        'border_radius' => '2px',
    ]);

    expect($p->accent)->toBe('#aa0000')
        ->and($p->buttonBg)->toBe('#00ff00')     // stored wins over derived accent
        ->and($p->buttonText)->toBe('#0000ff')
        ->and($p->borderRadius)->toBe('2px');
});

it('takes bg and text from the theme-mode palette anchors', function () {
    // One mode survives the 2026-08-06 simplification, so this asserts the
    // lookup still runs rather than that it can pick between palettes.
    $p = EmailBrandDefaults::palette(['theme_mode' => 'bleach']);

    expect($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#181818');
});

it('falls back to bleach anchors for an unknown theme_mode', function () {
    $p = EmailBrandDefaults::palette(['theme_mode' => 'neon']);

    expect($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#181818');
});

it('ignores empty-string stored values and falls back', function () {
    $p = EmailBrandDefaults::palette(['color_accent' => '']);
    expect($p->accent)->toBe('#1367fb');
});
