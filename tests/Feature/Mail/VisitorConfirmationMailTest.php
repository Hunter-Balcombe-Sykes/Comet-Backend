<?php

use App\Mail\EnquiryConfirmationMail;

it('builds the enquiry confirmation with subject, body, and pro reply-to', function () {
    $mail = new EnquiryConfirmationMail(
        proDisplayName: 'Test Pro',
        visitorName: 'Sarah',
        subject: 'Press',
        siteUrl: 'https://testpro.partna.au',
        replyToEmail: 'pro@example.com',
    );

    $mail->assertHasSubject('We received your enquiry — Test Pro');
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('Press');
    $mail->assertHasReplyTo('pro@example.com');
});

it('falls back to the Partna reply-to when the pro has no contact email', function () {
    $mail = new EnquiryConfirmationMail(
        proDisplayName: 'Test Pro',
        visitorName: 'Sarah',
        subject: 'Press',
        siteUrl: 'https://testpro.partna.au',
        replyToEmail: null,
    );

    $mail->assertHasReplyTo('hello@partna.au');
});

it('builds the subscription confirmation with subject, body, and unsubscribe link', function () {
    $mail = new App\Mail\SubscriptionConfirmationMail(
        proDisplayName: 'Test Pro',
        siteUrl: 'https://testpro.partna.au',
        unsubscribeUrl: 'https://api.partna.au/api/public/unsubscribe/tok123',
        visitorName: 'Sarah',
    );

    $mail->assertHasSubject("You're subscribed — Test Pro");
    $mail->assertSeeInHtml('Test Pro');
    $mail->assertSeeInHtml('https://api.partna.au/api/public/unsubscribe/tok123');
});
