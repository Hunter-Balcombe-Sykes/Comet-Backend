<?php

use App\Mail\Security\PasswordChangedMail;
use App\Mail\Security\TwoFactorEnabledMail;
use App\Mail\Security\TwoFactorRemovedMail;

it('renders the two-factor-removed notice', function () {
    $html = (new TwoFactorRemovedMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Two-factor authentication was removed')
        ->and($html)->toContain('Hi Sam,')
        ->and($html)->toContain('change your password');
});

it('renders the password-changed notice', function () {
    $html = (new PasswordChangedMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Your password was changed')
        ->and($html)->toContain('reset it now');
});

it('renders the two-factor-enabled notice', function () {
    $html = (new TwoFactorEnabledMail('sam@example.com', 'Sam'))->render();

    expect($html)->toContain('Two-factor authentication is on');
});
