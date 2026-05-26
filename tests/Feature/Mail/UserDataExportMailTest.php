<?php

use App\Mail\Gdpr\UserDataExportMail;

it('renders the self-service variant without the staff banner', function () {
    $mail = new UserDataExportMail(
        signedUrl: 'https://r2.example.com/exports/abc/def.zip?signed=1',
        professionalHandle: 'jane',
        sendTo: 'professional',
        recordCounts: ['customers' => 10, 'booking_events' => 5],
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('https://r2.example.com/exports/abc/def.zip?signed=1');
    expect($rendered)->toContain('7 days');
    expect($rendered)->not->toContain('staff data-handling SOP');
});

it('renders the staff variant with the PII banner', function () {
    $mail = new UserDataExportMail(
        signedUrl: 'https://r2.example.com/exports/abc/def.zip?signed=1',
        professionalHandle: 'jane',
        sendTo: 'staff',
        recordCounts: ['customers' => 10],
    );

    $rendered = $mail->render();

    expect($rendered)->toContain('staff data-handling SOP');
    expect($rendered)->toContain('jane');
});

it('uses different subject lines for each variant', function () {
    $self = new UserDataExportMail('https://x', 'jane', 'professional', []);
    $staff = new UserDataExportMail('https://x', 'jane', 'staff', []);

    expect($self->build()->subject)->toContain('Your Partna data export');
    expect($staff->build()->subject)->toContain('jane');
});
