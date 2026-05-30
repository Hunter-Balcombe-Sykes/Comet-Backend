<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\EnquiryConfirmationMail;
use App\Mail\SubscriptionConfirmationMail;

// Both confirmation Mailables now take an immutable EmailBrand (white-label
// branding bundle) rather than loose proDisplayName/siteUrl args. These tests
// drive the Mailables directly with a hand-built brand — no DB — to assert the
// envelope (subject, reply-to) and rendered body still hold after the refactor.

function testBrand(?string $replyToEmail): EmailBrand
{
    return new EmailBrand(
        isPartna: false,
        proName: 'Test Pro',
        siteUrl: 'https://testpro.partna.au',
        logoUrl: null,
        replyToEmail: $replyToEmail,
        palette: EmailBrandDefaults::defaults(),
    );
}

it('builds the enquiry confirmation with subject, body, and pro reply-to', function () {
    $mail = new EnquiryConfirmationMail(
        brand: testBrand('pro@example.com'),
        visitorName: 'Sarah',
        subject: 'Press',
    );

    $mail->assertHasSubject('We received your enquiry — Test Pro');
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('Press');
    $mail->assertHasReplyTo('pro@example.com');
});

it('falls back to the Partna reply-to when the pro has no contact email', function () {
    $mail = new EnquiryConfirmationMail(
        brand: testBrand(null),
        visitorName: 'Sarah',
        subject: 'Press',
    );

    $mail->assertHasReplyTo('hello@partna.au');
});

it('builds the subscription confirmation with subject, body, and unsubscribe link', function () {
    $mail = new SubscriptionConfirmationMail(
        brand: testBrand(null),
        unsubscribeUrl: 'https://api.partna.au/api/public/unsubscribe/tok123',
        visitorName: 'Sarah',
    );

    $mail->assertHasSubject("You're subscribed — Test Pro");
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('https://api.partna.au/api/public/unsubscribe/tok123');
});
