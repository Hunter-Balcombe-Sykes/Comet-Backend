<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;

it('renders pro logo + accent button in white-label mode', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: 'https://media.partna.au/logo.webp',
        replyToEmail: null,
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $html = view('mail.layouts.partna', ['brand' => $brand])
        ->with('__test_content', 'BODY')
        ->render();

    // 'sent via' is split from 'Partna' by an anchor tag in the footer — check both parts.
    expect($html)->toContain('https://media.partna.au/logo.webp')
        ->and($html)->toContain('sent via')
        ->and($html)->toContain('>Partna</a>')
        ->and($html)->not->toContain('email-wordmark.png'); // Partna wordmark hidden
});

it('falls back to Partna branding when no brand is passed', function () {
    $html = view('mail.layouts.partna')->render();

    expect($html)->toContain('email-wordmark.png')
        ->and($html)->not->toContain('sent via Partna');
});
