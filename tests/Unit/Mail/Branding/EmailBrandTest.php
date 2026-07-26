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
        ->and($b->logoUrlLight)->toContain('partna-wordmark-light.png')
        ->and($b->logoUrlDark)->toContain('partna-wordmark-dark.png')
        ->and($b->replyToEmail)->toBeNull()
        ->and($b->palette->accent)->toBe(EmailBrandDefaults::ACCENT);
});

it('sources logo URLs from the frontend SPA domain, not the API domain', function () {
    // app.url is the API's own domain (and is unset -> localhost in some deployed
    // envs) — it never serves /branding/*. Regression guard for exactly that bug:
    // the logo must resolve against app.frontend_url regardless of what app.url is.
    config()->set('app.url', 'http://localhost');
    config()->set('app.frontend_url', 'https://app.partna.au');

    $b = EmailBrand::partna();

    expect($b->logoUrlLight)->toBe('https://app.partna.au/branding/partna-wordmark-light.png')
        ->and($b->logoUrlDark)->toBe('https://app.partna.au/branding/partna-wordmark-dark.png');
});

it('round-trips through toArray/fromArray (cache payload)', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: 'https://media.example/logo.webp',
        logoUrlLight: null,
        logoUrlDark: null,
        iconUrlLight: null,
        iconUrlDark: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000']),
    );

    $rebuilt = EmailBrand::fromArray($brand->toArray());

    expect($rebuilt->isPartna)->toBeFalse()
        ->and($rebuilt->proName)->toBe('Jane Doe')
        ->and($rebuilt->siteUrl)->toBe('https://jane.partna.au')
        ->and($rebuilt->logoUrl)->toBe('https://media.example/logo.webp')
        ->and($rebuilt->logoUrlLight)->toBeNull()
        ->and($rebuilt->logoUrlDark)->toBeNull()
        ->and($rebuilt->replyToEmail)->toBe('jane@example.com')
        ->and($rebuilt->palette)->toBeInstanceOf(EmailPalette::class)
        ->and($rebuilt->palette->accent)->toBe('#aa0000')
        ->and($rebuilt->palette->buttonBg)->toBe('#aa0000');
});
