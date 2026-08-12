<?php

use App\Mail\Security\TwoFactorRemovedMail;

it('renders the two-factor-removed notice', function () {
    $html = (new TwoFactorRemovedMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Two-factor authentication was removed')
        ->and($html)->toContain('Hi Sam,')
        ->and($html)->toContain('change your password');
});
