<?php

use App\Mail\Gdpr\ProfessionalDataExportMail;

// BaseTransactionalMail::subject() is the central CRLF chokepoint for every
// transactional mailable. Symfony Mailer also strips newlines from headers,
// but CVE-2026-45067 confirmed that defence isn't bulletproof — so we filter
// at the setter for every subclass automatically. ProfessionalDataExportMail
// is used here purely as a concrete (non-abstract) subclass; the assertions
// exercise BaseTransactionalMail::subject() behaviour.

it('strips a CRLF run from the subject', function () {
    $mail = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Header\r\nInjection: evil");

    expect($mail->subject)->toBe('Header Injection: evil');
});

it('collapses adjacent newline runs to a single space', function () {
    $mail = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Line1\r\n\r\n\r\nLine2");

    expect($mail->subject)->toBe('Line1 Line2');
});

it('strips bare CR and bare LF as well as CRLF', function () {
    $mail = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Old-Mac\rline\nUnix");

    expect($mail->subject)->toBe('Old-Mac line Unix');
});

it('leaves clean subjects untouched, including em-dashes and tabs', function () {
    $mail = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Partna data export — jane\t(staff)");

    expect($mail->subject)->toBe("Partna data export — jane\t(staff)");
});

it('still returns the mailable for chaining', function () {
    $mail = new ProfessionalDataExportMail('https://x', 'jane', 'professional', []);
    $result = $mail->subject('Plain subject');

    expect($result)->toBe($mail);
});
