<?php

use App\Listeners\BlockSuppressedRecipients;
use App\Models\Core\EmailSuppression;
use App\Services\Notifications\EmailSuppressionService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    setupEmailSuppressionsTable();
});

function messageSendingTo(string $to): MessageSending
{
    $email = (new Email)
        ->from('hello@partna.au')
        ->to($to)
        ->subject('Your verification code')
        ->text('123456');

    return new MessageSending($email);
}

it('cancels the send (returns false) for a suppressed recipient', function () {
    app(EmailSuppressionService::class)->suppress('blocked@example.com', EmailSuppression::REASON_COMPLAINT, 'resend', null);

    $result = app(BlockSuppressedRecipients::class)->handle(messageSendingTo('blocked@example.com'));

    expect($result)->toBeFalse();
});

it('allows the send (does not return false) for a non-suppressed recipient', function () {
    $result = app(BlockSuppressedRecipients::class)->handle(messageSendingTo('fine@example.com'));

    expect($result)->not->toBeFalse();
});

it('fails OPEN — allows the send + logs a warning when the suppression lookup throws', function () {
    // Drop the table so EmailSuppressionService::isSuppressed() throws. An OTP
    // must never be blocked by a suppression-store outage.
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS core.email_suppressions');
    Log::spy();

    $result = app(BlockSuppressedRecipients::class)->handle(messageSendingTo('someone@example.com'));

    expect($result)->not->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

it('actually blocks delivery end-to-end via the array transport', function () {
    app(EmailSuppressionService::class)->suppress('blocked@example.com', EmailSuppression::REASON_HARD_BOUNCE, 'resend', 'Suppressed');
    Config::set('mail.default', 'array');

    Mail::raw('hello', fn ($m) => $m->to('blocked@example.com')->subject('t'));
    Mail::raw('hello', fn ($m) => $m->to('fine@example.com')->subject('t'));

    // Only the non-suppressed recipient's message reaches the transport.
    $messages = collect(Mail::getSymfonyTransport()->messages());
    expect($messages)->toHaveCount(1);
});
