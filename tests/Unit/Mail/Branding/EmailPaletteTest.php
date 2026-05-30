<?php

use App\Mail\Branding\EmailPalette;

it('exposes all eight email-safe tokens as readonly strings', function () {
    $p = new EmailPalette(
        accent: '#111111',
        accentContrast: '#ffffff',
        bg: '#fafafa',
        text: '#222222',
        textMuted: '#888888',
        buttonBg: '#111111',
        buttonText: '#ffffff',
        borderRadius: '8px',
    );

    expect($p->accent)->toBe('#111111')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#fafafa')
        ->and($p->text)->toBe('#222222')
        ->and($p->textMuted)->toBe('#888888')
        ->and($p->buttonBg)->toBe('#111111')
        ->and($p->buttonText)->toBe('#ffffff')
        ->and($p->borderRadius)->toBe('8px');
});
