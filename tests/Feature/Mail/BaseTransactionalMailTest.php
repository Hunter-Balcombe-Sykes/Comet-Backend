<?php

use App\Mail\Gdpr\UserDataExportMail;

// BaseTransactionalMail::subject() is the central CRLF chokepoint for every
// transactional mailable. Symfony Mailer also strips newlines from headers,
// but CVE-2026-45067 confirmed that defence isn't bulletproof — so we filter
// at the setter for every subclass automatically. UserDataExportMail
// is used here purely as a concrete (non-abstract) subclass; the assertions
// exercise BaseTransactionalMail::subject() behaviour.

it('strips a CRLF run from the subject', function () {
    $mail = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Header\r\nInjection: evil");

    expect($mail->subject)->toBe('Header Injection: evil');
});

it('collapses adjacent newline runs to a single space', function () {
    $mail = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Line1\r\n\r\n\r\nLine2");

    expect($mail->subject)->toBe('Line1 Line2');
});

it('strips bare CR and bare LF as well as CRLF', function () {
    $mail = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Old-Mac\rline\nUnix");

    expect($mail->subject)->toBe('Old-Mac line Unix');
});

it('leaves clean subjects untouched, including em-dashes and tabs', function () {
    $mail = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $mail->subject("Partna data export — jane\t(staff)");

    expect($mail->subject)->toBe("Partna data export — jane\t(staff)");
});

it('still returns the mailable for chaining', function () {
    $mail = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $result = $mail->subject('Plain subject');

    expect($result)->toBe($mail);
});

// WHK-5 regression — non-auth mailables must never hit "must not be accessed
// before initialization" on $webhookId, and must not produce a pinned Message-ID.
it('WHK-5 regression: non-auth mailable headers() does not throw and returns no pinned Message-ID', function () {
    // UserDataExportMail never sets $webhookId — this proves the empty-string
    // default prevents the "uninitialized typed property" fatal, and that the
    // guard returns an empty Headers() so Symfony auto-generates the Message-ID
    // rather than emitting the invalid "@partna.au" local-part.
    $mail = new UserDataExportMail('https://example.com/export.zip', 'jane', 'professional', []);

    $headers = $mail->headers();

    // No pinned id — Symfony will generate one at send time.
    expect($headers->messageId)->toBeNull();
});
