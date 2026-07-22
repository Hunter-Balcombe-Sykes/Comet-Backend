<?php

use App\Models\Core\EmailSuppression;
use App\Services\Notifications\EmailSuppressionService;
use App\Support\EmailHasher;

beforeEach(function (): void {
    setupEmailSuppressionsTable();
});

it('suppress() stores a hashed row — never the plaintext email', function () {
    $service = app(EmailSuppressionService::class);
    $email = 'bounced@example.com';

    $service->suppress($email, EmailSuppression::REASON_HARD_BOUNCE, 'resend', 'Suppressed');

    $row = EmailSuppression::firstOrFail();
    expect($row->email_hash)->toBe(EmailHasher::hash($email))
        ->and($row->email_hash)->not->toBe($email)
        ->and($row->reason)->toBe('hard_bounce')
        ->and($row->source)->toBe('resend')
        ->and($row->detail)->toBe('Suppressed')
        ->and($row->first_seen_at)->not->toBeNull();
});

it('suppress() is idempotent — the same address twice yields exactly one row', function () {
    $service = app(EmailSuppressionService::class);
    $email = 'dupe@example.com';

    $service->suppress($email, EmailSuppression::REASON_HARD_BOUNCE, 'resend', 'General');
    $firstSeen = EmailSuppression::firstOrFail()->first_seen_at;

    // A later complaint for the same address updates the reason but must not
    // create a second row, and must preserve the original first_seen_at.
    $service->suppress($email, EmailSuppression::REASON_COMPLAINT, 'resend', null);

    expect(EmailSuppression::count())->toBe(1);
    $row = EmailSuppression::firstOrFail();
    expect($row->reason)->toBe('complaint')
        ->and($row->first_seen_at->timestamp)->toBe($firstSeen->timestamp);
});

it('isSuppressed() is true for a suppressed address and false otherwise', function () {
    $service = app(EmailSuppressionService::class);
    $service->suppress('blocked@example.com', EmailSuppression::REASON_COMPLAINT, 'resend', null);

    expect($service->isSuppressed('blocked@example.com'))->toBeTrue()
        ->and($service->isSuppressed('fine@example.com'))->toBeFalse();
});

it('isSuppressed() matches regardless of case and surrounding whitespace', function () {
    $service = app(EmailSuppressionService::class);
    $service->suppress('Mixed@Example.com', EmailSuppression::REASON_HARD_BOUNCE, 'resend', null);

    expect($service->isSuppressed('  mixed@example.com  '))->toBeTrue();
});

it('suppress() with a blank address is a no-op', function () {
    $service = app(EmailSuppressionService::class);
    $service->suppress('   ', EmailSuppression::REASON_MANUAL, null, null);

    expect(EmailSuppression::count())->toBe(0);
});
