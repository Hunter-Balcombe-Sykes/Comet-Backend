<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;
use Tests\TestCase;

// config() requires the Laravel container — opt into TestCase bootstrapping.
uses(TestCase::class)->in(__FILE__);

it('builds a Partna-branded brand from defaults', function () {
    config()->set('mail.from.name', 'Partna');

    $b = EmailBrand::partna();

    expect($b->isPartna)->toBeTrue()
        ->and($b->proName)->toBe('Partna')
        ->and($b->siteUrl)->toBe('https://partna.au')
        ->and($b->logoUrl)->toBeNull()
        ->and($b->iconUrl)->toContain('partna-icon.png')
        ->and($b->wordmarkUrl)->toContain('partna-wordmark.png')
        ->and($b->replyToEmail)->toBeNull()
        ->and($b->palette->accent)->toBe(EmailBrandDefaults::ACCENT);
});

it('sources logo URLs from the API domain, which ships the assets itself', function () {
    // The PNGs live in this repo's public/branding/ (2026-08-12) — email
    // branding no longer depends on whichever frontend serves app.partna.au.
    config()->set('app.url', 'https://api.partna.au');

    $b = EmailBrand::partna();

    expect($b->iconUrl)->toBe('https://api.partna.au/branding/partna-icon.png')
        ->and($b->wordmarkUrl)->toBe('https://api.partna.au/branding/partna-wordmark.png');
});

it('round-trips through toArray/fromArray (cache payload)', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: 'https://media.example/logo.webp',
        iconUrl: null,
        wordmarkUrl: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000']),
    );

    $rebuilt = EmailBrand::fromArray($brand->toArray());

    expect($rebuilt->isPartna)->toBeFalse()
        ->and($rebuilt->proName)->toBe('Jane Doe')
        ->and($rebuilt->siteUrl)->toBe('https://jane.partna.au')
        ->and($rebuilt->logoUrl)->toBe('https://media.example/logo.webp')
        ->and($rebuilt->iconUrl)->toBeNull()
        ->and($rebuilt->wordmarkUrl)->toBeNull()
        ->and($rebuilt->replyToEmail)->toBe('jane@example.com')
        ->and($rebuilt->palette)->toBeInstanceOf(EmailPalette::class)
        ->and($rebuilt->palette->accent)->toBe('#aa0000')
        ->and($rebuilt->palette->buttonBg)->toBe('#aa0000');
});
