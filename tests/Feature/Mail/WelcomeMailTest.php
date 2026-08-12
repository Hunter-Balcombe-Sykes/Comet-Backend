<?php

use App\Mail\Account\WelcomeMail;

it('renders the welcome email with the site address', function () {
    $html = (new WelcomeMail('sam@example.com', 'sams-cafe'))->render();

    expect($html)->toContain('Welcome to Partna')
        ->and($html)->toContain('sams-cafe.partna.au')
        ->and($html)->toContain('Open your dashboard');
});
