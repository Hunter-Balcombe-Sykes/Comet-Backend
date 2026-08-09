<?php

use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;

it('returns the static defaults when the kit is empty', function () {
    $p = EmailBrandDefaults::palette([]);

    // bg/text are the bleach (default theme-mode) palette anchors since the
    // 2026-07-10 rework — color_bg/color_text no longer feed the email palette.
    // The ink moved from #181818 to the grey ramp's gray-900 (#141414) on
    // 2026-08-09; borderRadius from '0' to '8px' with the corners decision
    // (brief §3.4). See ThemeModePalettes / EmailBrandDefaults::BORDER_RADIUS.
    expect($p)->toBeInstanceOf(EmailPalette::class)
        ->and($p->accent)->toBe('#1367fb')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#141414')
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

// 2026-08-09 preset-only: border_radius (a CSS length) was dropped and the
// corner choice is a SELECTION now. The email palette resolves it through the
// same two-value table the design system ships (CORNER_PRESETS), in px because
// rem is unreliable in email clients.
it('resolves the corners selection to an email-safe px radius', function () {
    expect(EmailBrandDefaults::palette(['corners' => 'default'])->borderRadius)->toBe('8px')
        ->and(EmailBrandDefaults::palette(['corners' => 'rounded'])->borderRadius)->toBe('16px')
        // An unknown selection is the default corner, never a raw echo into
        // the style attribute.
        ->and(EmailBrandDefaults::palette(['corners' => 'pill'])->borderRadius)->toBe('8px');
});

it('still honours a legacy border_radius length from a pre-migration payload', function () {
    // EmailBrand::fromArray() hydrates from a queued job's serialised payload,
    // so a job enqueued before the deploy is drained after it. A stored length
    // is still valid CSS; the fallback goes when no queue can carry one.
    expect(EmailBrandDefaults::palette(['border_radius' => '2px'])->borderRadius)->toBe('2px');
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
        ->and($p->text)->toBe('#141414');
});

it('falls back to bleach anchors for an unknown theme_mode', function () {
    $p = EmailBrandDefaults::palette(['theme_mode' => 'neon']);

    expect($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#141414');
});

it('ignores empty-string stored values and falls back', function () {
    $p = EmailBrandDefaults::palette(['color_accent' => '']);
    expect($p->accent)->toBe('#1367fb');
});
