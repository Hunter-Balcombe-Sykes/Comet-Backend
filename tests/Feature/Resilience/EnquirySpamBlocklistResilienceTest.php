<?php

use App\Services\Notifications\EnquirySpamBlocklist;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('treats a sender as not blocklisted when Redis is unreachable', function () {
    Redis::shouldReceive('connection')
        ->with('app')
        ->andThrow(new RuntimeException('read error on connection to 127.0.0.1:6379'));

    // Fails OPEN, and the asymmetry is the whole point: the blocked branch in
    // PublicEnquiryController returns a FAKE 200 and silently discards. Failing
    // closed here would bin every legitimate enquiry during an outage with no
    // error surfaced anywhere. One spam enquiry reaching an inbox is trivially
    // recoverable; silently deleting real leads is not.
    expect(app(EnquirySpamBlocklist::class)->contains((string) Str::uuid(), 'spammer@example.test'))
        ->toBeFalse();
});

it('emits a breadcrumb when the blocklist read degrades', function () {
    Redis::shouldReceive('connection')
        ->with('app')
        ->andThrow(new RuntimeException('read error on connection to 127.0.0.1:6379'));

    Log::spy();

    app(EnquirySpamBlocklist::class)->contains((string) Str::uuid(), 'spammer@example.test');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'enquiry.blocklist.unavailable')
        ->once();
});

it('still throws when a blocklist WRITE cannot reach Redis', function () {
    Redis::shouldReceive('connection')
        ->with('app')
        ->andThrow(new RuntimeException('read error on connection to 127.0.0.1:6379'));

    // Reads degrade; writes stay loud. add() is reached from
    // UserEnquiryController::report() — the professional clicking "block this
    // sender". A silent no-op would leave them believing a sender is blocked
    // when they are not.
    expect(fn () => app(EnquirySpamBlocklist::class)->add((string) Str::uuid(), 'spammer@example.test'))
        ->toThrow(RuntimeException::class);
});
