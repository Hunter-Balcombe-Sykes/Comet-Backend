<?php

use App\Mail\Notifications\AchievementMail;
use App\Models\Core\Notifications\Notification;

it('renders an achievement through the shared category view with unsubscribe', function () {
    $n = (new Notification)->forceFill([
        'user_id' => '00000000-0000-4000-8000-000000000001',
        'category' => 'achievement',
        'title' => 'Your first enquiry just arrived',
        'body' => 'Someone reached out through your site. Reply while it\'s warm.',
        'cta_url' => 'https://app.partna.au/contact',
        'primary_action_label' => 'Open enquiries',
    ]);

    $html = (new AchievementMail($n))->render();

    expect($html)->toContain('Your first enquiry just arrived')
        ->and($html)->toContain('Unsubscribe');
});
