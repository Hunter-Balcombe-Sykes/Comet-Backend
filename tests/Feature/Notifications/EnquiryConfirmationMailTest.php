<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\EnquiryConfirmationMail;

it('renders the enquiry confirmation with pro name, accent button and reply-to', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: null,
        logoUrlLight: null,
        logoUrlDark: null,
        iconUrlLight: null,
        iconUrlDark: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $mail = (new EnquiryConfirmationMail(
        brand: $brand,
        visitorName: 'Sam',
        subject: 'A new project',
    ))->build();

    $rendered = $mail->render();

    expect($rendered)->toContain('Jane Doe')
        ->and($rendered)->toContain('Sam')
        ->and($rendered)->toContain('A new project')
        ->and($rendered)->toContain('#aa0000')             // accent button
        ->and($rendered)->toContain('jane.partna.au');

    $mail->assertHasSubject('We received your enquiry — Jane Doe');
    $mail->assertHasReplyTo('jane@example.com', 'Jane Doe');
    $mail->assertFrom(config('mail.from.address', 'hello@partna.au'), 'Jane Doe');
});
