<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Mail\SiteEnquiryNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupProfessionalsTable();
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
        'primary_email' => 'pro@example.test',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'testpro',
        'is_published' => 1,
    ]);

    $blockRow = array_merge([
        'id' => $blockId,
        'professional_id' => $proId,
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
        'professional_id' => $proId,
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

// B3/P1-10: failed() must not leak the notification_email — it's not even on the
// job any more, but make sure the log shape is documented in a test.
it('failed: log context carries UUIDs only (no notification_email)', function () {
    Log::spy();

    $exception = new \RuntimeException('mailer down');

    // Bind a noop exception handler so report() in failed() doesn't propagate.
    app()->bind(\Illuminate\Contracts\Debug\ExceptionHandler::class, fn () => new class implements \Illuminate\Contracts\Debug\ExceptionHandler
    {
        public function report(\Throwable $e): void {}

        public function shouldReport(\Throwable $e): bool
        {
            return false;
        }

        public function render($request, \Throwable $e): \Symfony\Component\HttpFoundation\Response
        {
            return new \Symfony\Component\HttpFoundation\Response;
        }

        public function renderForConsole($output, \Throwable $e): void {}
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
