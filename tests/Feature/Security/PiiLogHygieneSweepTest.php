<?php

// B3: PII/log hygiene sweep — source-file regression guard.
//
// These are not behaviour tests. They're shape assertions that prevent a future
// dev from re-introducing the exact patterns this bundle removed. Behavioural
// coverage lives in the per-file unit tests; this file is the second-line tripwire.
//
// TestCase is auto-applied to tests/Feature/* by tests/Pest.php — no `uses(...)` needed.

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Jobs\Notifications\SyncCustomerMarketingOptInJob;

/** Read a source file from the repo root. */
function readSource(string $relativePath): string
{
    return (string) file_get_contents(base_path($relativePath));
}

it('B3/P1-09: no $response->body() leaks in AccountDeletionService log contexts', function () {
    $src = readSource('app/Services/User/AccountDeletionService.php');

    expect($src)
        ->not->toContain("'body' => \$response->body()")
        ->and($src)->not->toContain('"body" => $response->body()');
});

it('B3/P1-09: SupabaseAdminService::unenrollMfaFactor does not embed response body in exception messages', function () {
    $src = readSource('app/Services/Auth/SupabaseAdminService.php');

    // The previous, leaky form was `"... body={$response->body()}"`. Any string
    // interpolation of $response->body() inside the SupabaseAdminService file
    // would be a regression.
    expect($src)->not->toContain('{$response->body()}');
});

it('B3/P1-10: SyncCustomerMarketingOptInJob constructor takes UUIDs only', function () {
    $ctor = (new ReflectionClass(SyncCustomerMarketingOptInJob::class))->getConstructor();
    $paramNames = array_map(fn ($p) => $p->getName(), $ctor->getParameters());

    expect($paramNames)
        ->toContain('userId')
        ->and($paramNames)->toContain('subscriptionId')
        ->and($paramNames)->not->toContain('email')
        ->and($paramNames)->not->toContain('subscribed');
});

it('B3/P1-10: SendEnquiryNotificationJob constructor takes UUIDs only', function () {
    $ctor = (new ReflectionClass(SendEnquiryNotificationJob::class))->getConstructor();
    $paramNames = array_map(fn ($p) => $p->getName(), $ctor->getParameters());

    expect($paramNames)
        ->toContain('enquiryId')
        ->and($paramNames)->toContain('blockId')
        ->and($paramNames)->not->toContain('notificationEmail')
        ->and($paramNames)->not->toContain('email');
});

it('B3/P2-11: no $response->body() leaks in streaming-client error logs', function () {
    foreach ([
        'app/Services/Streaming/KickApiClient.php',
        'app/Services/Streaming/TwitchApiClient.php',
    ] as $relPath) {
        $src = readSource($relPath);
        expect($src)
            ->not->toContain("'body' => \$response->body()")
            ->and($src)->not->toContain('"body" => $response->body()');
    }
});

it('B3/P2-12: StaffAuditService.write_failed log includes request_id', function () {
    $src = readSource('app/Services/Audit/StaffAuditService.php');

    expect($src)->toContain("'request_id' => request()?->header('X-Request-Id')");
});
