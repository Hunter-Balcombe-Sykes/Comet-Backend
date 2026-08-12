<?php

use App\Mail\Support\CategoryUnsubscribe;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('builds a signed unsubscribe URL for an optional category', function () {
    $url = CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'feature_announcement');

    expect($url)->toContain('/public/notification-unsubscribe/00000000-0000-4000-8000-000000000001/feature_announcement')
        ->and($url)->toContain('signature=');
});

it('returns null for critical, mandatory, empty category and missing user', function () {
    expect(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'critical'))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', 'policy_update'))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor('00000000-0000-4000-8000-000000000001', ''))->toBeNull()
        ->and(CategoryUnsubscribe::urlFor(null, 'feature_announcement'))->toBeNull();
});
