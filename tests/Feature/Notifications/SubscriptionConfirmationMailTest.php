<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\SubscriptionConfirmationMail;

it('renders the subscription confirmation with unsubscribe + one-click headers', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: null,
        logoUrlLight: null,
        logoUrlDark: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $mail = (new SubscriptionConfirmationMail(
        brand: $brand,
        unsubscribeUrl: 'https://jane.partna.au/unsubscribe/tok',
        visitorName: 'Sam',
    ))->build();

    $rendered = $mail->render();

    expect($rendered)->toContain('Jane Doe')
        ->and($rendered)->toContain('Sam')
        ->and($rendered)->toContain('https://jane.partna.au/unsubscribe/tok')
        ->and($rendered)->toContain('#aa0000');

    $mail->assertHasSubject("You're subscribed — Jane Doe");
    $mail->assertHasReplyTo('jane@example.com', 'Jane Doe');
    $mail->assertFrom(config('mail.from.address', 'hello@partna.au'), 'Jane Doe');
});
