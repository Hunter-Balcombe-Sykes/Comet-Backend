<?php

require_once __DIR__.'/../../Helpers/EnquiryInboxTestHelpers.php';

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Mail\EnquiryConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

function seedConfirmableEnquiry(array $enquiryOverrides = [], array $blockSettings = ['notification_email' => 'pro@example.com']): array
{
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    seedContactBlock($siteId, $user->id, $blockSettings);
    $enquiryId = seedInboxEnquiry($user->id, $siteId, array_merge([
        'email' => 'visitor@example.com',
        'name' => 'Vee',
        'subject' => 'Press',
    ], $enquiryOverrides));

    return [$user, $enquiryId];
}

it('sends a confirmation to the visitor and stamps confirmation_sent_at', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry();

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, fn ($m) => $m->hasTo('visitor@example.com'));

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->confirmation_sent_at)->not->toBeNull();
});

it('is idempotent — does not re-send once confirmation_sent_at is set', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry(['confirmation_sent_at' => now()->toDateTimeString()]);

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});

it('respects the per-block send_visitor_confirmation = false toggle', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry([], [
        'notification_email' => 'pro@example.com',
        'send_visitor_confirmation' => false,
    ]);

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});

it('drops the send when the per-recipient rate limit is exceeded', function () {
    Mail::fake();
    [, $enquiryId] = seedConfirmableEnquiry();

    $key = 'visitor_confirmation:'.hash('sha256', 'visitor@example.com');
    $limit = (int) config('partna.throttle.visitor_confirmation_per_hour', 5);
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }

    (new SendEnquiryConfirmationJob($enquiryId))->handle();

    Mail::assertNothingSent();
});
