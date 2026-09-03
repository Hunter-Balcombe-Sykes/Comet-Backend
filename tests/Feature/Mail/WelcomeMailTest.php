<?php

use App\Mail\Account\WelcomeMail;

it('renders the welcome email with the site address', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe'))->render();

    expect($html)->toContain('Welcome to Partna')
        ->and($html)->toContain('sams-cafe.partna.au')
        ->and($html)->toContain('Open your dashboard');
});

it('lists connected platforms when there are any', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe', ['Instagram', 'Google Business']))->render();

    expect($html)->toContain('Instagram')
        ->and($html)->toContain('Google Business');
});

// An empty list must render the pre-existing email exactly, not an empty
// bullet list or a dangling heading.
it('renders the plain welcome when nothing connected', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe', []))->render();

    expect($html)->toContain('Welcome to Partna')
        ->and($html)->toContain('sams-cafe.partna.au')
        ->and($html)->not->toContain('Already connected');
});
