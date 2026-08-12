<?php

use App\Mail\Account\WeeklyDigestMail;

it('renders the digest with the week numbers and unsubscribe affordance', function () {
    $html = (new WeeklyDigestMail(
        'sam@example.com', 'Sam', '4–10 August',
        214, 161, 38, 'Instagram', 17,
        'https://api.partna.au/api/public/notification-unsubscribe/u/analytics_weekly?signature=x',
    ))->render();

    expect($html)->toContain('Your week on Partna')
        ->and($html)->toContain('214')
        ->and($html)->toContain('161')
        ->and($html)->toContain('38')
        ->and($html)->toContain('Instagram')
        ->and($html)->toContain('Unsubscribe');
});

it('omits the top-link row and unsubscribe when absent', function () {
    $html = (new WeeklyDigestMail('sam@example.com', null, '4–10 August', 3, 2, 0, null, null, null))->render();

    expect($html)->not->toContain('Most tapped')
        ->and($html)->not->toContain('Unsubscribe');
});
