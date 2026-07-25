<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Mail\SiteEnquiryNotification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupBlocksTable();
    setupEnquiriesTable();
});

function seedEnquiryAndBlock(array $blockOverrides = []): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $blockId = (string) Str::uuid();
    $enquiryId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'testpro',
        'handle_lc' => 'testpro',
        'display_name' => 'Testpro',
        'first_name' => 'Testpro',
        'primary_email' => 'pro@example.test',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
        'subdomain' => 'testpro',
        'is_published' => 1,
    ]);

    $blockRow = array_merge([
        'id' => $blockId,
        'user_id' => $proId,
        'site_id' => $siteId,
        'block_group' => 'sections',
        'block_type' => 'contact',
        'is_active' => 1,
        'is_enabled' => 1,
        'settings' => json_encode(['notification_email' => 'hello@mybrand.com']),
        'created_at' => $now,
        'updated_at' => $now,
    ], $blockOverrides);

    DB::connection('pgsql')->table('site.blocks')->insert($blockRow);

    DB::connection('pgsql')->table('site.enquiries')->insert([
        'id' => $enquiryId,
        'user_id' => $proId,
        'site_id' => $siteId,
        'name' => 'Visitor',
        'email' => 'visitor@example.test',
        'subject' => 'Wholesale',
        'message' => 'Test enquiry message.',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$enquiryId, $blockId];
}

// B3/P1-10: handle() reads notification_email from the contact block at job-run
// time rather than from a constructor prop. Verifies the happy path actually sends.
it('handle: looks up notification_email from the block settings and sends to it', function () {
    Mail::fake();
    [$enquiryId, $blockId] = seedEnquiryAndBlock();

    (new SendEnquiryNotificationJob($enquiryId, $blockId))->handle();

    Mail::assertSent(SiteEnquiryNotification::class, function ($mail) {
        return $mail->hasTo('hello@mybrand.com');
    });
});

// B3/P1-10: GDPR-erased block (or block that the brand deleted) must not produce
// a mail send. Quiet warning, no PII in the log context.
it('handle: no-ops with a warning when the contact block has been deleted', function () {
    Mail::fake();
    Log::spy();

    [$enquiryId, $blockId] = seedEnquiryAndBlock();
    DB::connection('pgsql')->table('site.blocks')->where('id', $blockId)->update(['deleted_at' => now()->toDateTimeString()]);

    (new SendEnquiryNotificationJob($enquiryId, $blockId))->handle();

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $msg, array $ctx) => $msg === 'SendEnquiryNotificationJob: contact block no longer available'
            && $ctx['enquiry_id'] === $enquiryId
            && $ctx['block_id'] === $blockId
            && ! array_key_exists('notification_email', $ctx)
    );
});

it('handle: no-ops with a warning when notification_email has been cleared from settings', function () {
    Mail::fake();
    Log::spy();

    [$enquiryId, $blockId] = seedEnquiryAndBlock(['settings' => json_encode([])]);

    (new SendEnquiryNotificationJob($enquiryId, $blockId))->handle();

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $msg, array $ctx) => $msg === 'SendEnquiryNotificationJob: notification_email no longer configured'
            && ! array_key_exists('notification_email', $ctx)
    );
});

// #SEM-10 reversed: email_sent_at is now stamped AFTER a successful send, not
// inside the lock transaction beforehand. If the send throws, the stamp must
// stay unset so a Horizon retry actually re-sends instead of silently no-oping
// at a stamp that was written before delivery ever succeeded.
it('handle: leaves email_sent_at unset when the send throws, so the retry re-sends', function () {
    [$enquiryId, $blockId] = seedEnquiryAndBlock();

    // Simulate a transient SMTP failure on the first attempt.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(fn () => (new SendEnquiryNotificationJob($enquiryId, $blockId))->handle())
        ->toThrow(RuntimeException::class);

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->email_sent_at)->toBeNull();

    // A fresh job instance (modelling a real Horizon retry — a new unserialized
    // instance, not the same in-memory object) must now actually send, and the
    // stamp must land only after that send succeeds. Mail::shouldReceive() above
    // swapped the facade root for a single-expectation Mockery partial mock;
    // swap in a real MailManager before Mail::fake() so the fake doesn't wrap
    // that now-exhausted mock.
    Mail::swap(new MailManager(app()));
    Mail::fake();
    (new SendEnquiryNotificationJob($enquiryId, $blockId))->handle();

    Mail::assertSent(SiteEnquiryNotification::class, 1);

    $row = DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiryId)->first();
    expect($row->email_sent_at)->not->toBeNull();
});

// SEM-10: idempotency — a second handle() call after a successful first send
// must not trigger a second mail send.
it('handle: does not resend when email_sent_at is already set (idempotency)', function () {
    Mail::fake();
    [$enquiryId, $blockId] = seedEnquiryAndBlock();

    $job = new SendEnquiryNotificationJob($enquiryId, $blockId);
    $job->handle(); // first run — sends and stamps

    Mail::assertSent(SiteEnquiryNotification::class, 1);

    $job->handle(); // second run — idempotency guard fires, no second send

    Mail::assertSent(SiteEnquiryNotification::class, 1);
});

// B3/P1-10: failed() must not leak the notification_email — it's not even on the
// job any more, but make sure the log shape is documented in a test.
it('failed: log context carries UUIDs only (no notification_email)', function () {
    Log::spy();

    $exception = new RuntimeException('mailer down');

    // Bind a noop exception handler so report() in failed() doesn't propagate.
    app()->bind(ExceptionHandler::class, fn () => new class implements ExceptionHandler
    {
        public function report(Throwable $e): void {}

        public function shouldReport(Throwable $e): bool
        {
            return false;
        }

        public function render($request, Throwable $e): Response
        {
            return new Response;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });

    (new SendEnquiryNotificationJob('enq-id-1', 'block-id-1'))->failed($exception);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $msg, array $ctx) => $msg === 'SendEnquiryNotificationJob failed permanently'
            && $ctx['enquiry_id'] === 'enq-id-1'
            && $ctx['block_id'] === 'block-id-1'
            && ! array_key_exists('notification_email', $ctx)
            && ! array_key_exists('email', $ctx)
    );
});
