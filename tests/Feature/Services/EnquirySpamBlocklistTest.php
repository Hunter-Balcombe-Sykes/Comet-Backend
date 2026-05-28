<?php

use App\Services\Notifications\EnquirySpamBlocklist;
use Illuminate\Support\Facades\Redis;

beforeEach(fn () => Redis::connection()->flushdb());

it('adds an email hash with 90-day expiry', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-123', 'spam@example.com');

    expect($svc->contains('user-123', 'spam@example.com'))->toBeTrue();
    expect($svc->contains('user-123', 'other@example.com'))->toBeFalse();
});

it('treats email case-insensitively', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-123', 'Spam@Example.COM');
    expect($svc->contains('user-123', 'spam@example.com'))->toBeTrue();
});

it('isolates blocklists per user', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->add('user-A', 'spam@example.com');
    expect($svc->contains('user-A', 'spam@example.com'))->toBeTrue();
    expect($svc->contains('user-B', 'spam@example.com'))->toBeFalse();
});

it('returns false for expired entries (synthetic past expiry)', function () {
    $svc = app(EnquirySpamBlocklist::class);
    $svc->addWithExpiry('user-123', 'spam@example.com', now()->subDay()->timestamp);
    expect($svc->contains('user-123', 'spam@example.com'))->toBeFalse();
});
