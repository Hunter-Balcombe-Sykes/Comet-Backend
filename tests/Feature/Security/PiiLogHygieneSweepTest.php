<?php

// B3: PII/log hygiene sweep — source-file regression guard.
//
// These are mostly shape assertions that prevent a future dev from re-introducing
// the exact patterns this bundle removed — not a substitute for exercising the
// code. Behavioural coverage (driving the actual log call and asserting on the
// captured context) exists where noted below; everything else here is a
// second-line tripwire only.
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

it('SEC-5: BootstrapController does not log the raw applicant email on the account-conflict path', function () {
    $src = readSource('app/Http/Controllers/Api/PublicSite/BootstrapController.php');

    expect($src)
        ->not->toContain("'email' => \$email")
        ->and($src)->toContain("'email_hash' => hash('sha256', mb_strtolower(trim((string) \$email)))");
    // Behavioural coverage: tests/Feature/PublicSite/BootstrapEmailRaceTest.php's
    // "does not log the raw applicant email" case drives EMAIL_ALREADY_REGISTERED
    // and asserts on the captured Log::info context.
});

it('SEC-102: no InstagramScraper Log:: call embeds an un-hashed Instagram username', function () {
    $src = readSource('app/Services/Platforms/InstagramScraper.php');

    expect($src)->not->toContain("'username' => \$username");

    // Isolate each Log::level('event', [...]); call (every call in this file ends
    // at the first "]);" after "Log::") and check none of them reference the bare
    // $username outside of hash('sha256', mb_strtolower($username)) — catches a
    // raw username slipped into a NEW log key, a differently-named call, or string
    // interpolation, not just the exact "'username' => $username" pattern above.
    preg_match_all('/Log::\w+\(.*?\]\);/s', $src, $matches);
    expect($matches[0])->not->toBeEmpty(); // sanity: this file's Log:: calls still match the expected shape

    foreach ($matches[0] as $call) {
        $bareUsernameRefs = substr_count($call, '$username') - substr_count($call, 'mb_strtolower($username)');
        expect($bareUsernameRefs)->toBe(0, "Log call embeds \$username outside hash(sha256, mb_strtolower()): {$call}");
    }

    // Belt-and-braces: attemptFetch() has 4 failure branches (threw / not_ok /
    // bad_items / error_item), each hashing the (lowercased) username, plus
    // logThinProfile()'s instagram.thin_profile line — 5 in total. If this
    // drops, a branch stopped hashing; if you add a genuine 6th hashing call
    // site, bump the number — the loop above is what actually guards against a
    // raw leak.
    expect(substr_count($src, "hash('sha256', mb_strtolower(\$username))"))->toBe(5);

    // Behavioural coverage: tests/Unit/Platforms/InstagramScraperTest.php's
    // "does not log the raw username" case drives the apify.threw branch and
    // asserts on the captured Log::warning context, including the normalised
    // (case-insensitive) hash from Defect 1.
});

it('B7/PRIV-1: CspReportController hashes the caller IP instead of logging it raw', function () {
    $src = readSource('app/Http/Controllers/Api/Internal/CspReportController.php');

    expect($src)
        ->not->toContain("'ip' => \$request->ip()")
        ->and($src)->toContain("'ip_hash' => \$this->hashIp(\$request->ip())");
    // Behavioural coverage: tests/Feature/Security/SecureHeadersTest.php's
    // "hashes the caller IP instead of logging it raw" case drives the
    // controller and asserts on the captured Log::error context.
});

it('B7/PRIV-1: AuthFactorEventRepository does not store a raw ip/user_agent on insert', function () {
    $src = readSource('app/Services/Auth/AuthFactorEventRepository.php');

    expect($src)
        ->not->toContain("'ip' => \$ip,")
        ->and($src)->not->toContain("'user_agent' => \$userAgent,");
    // Behavioural coverage: tests/Feature/Auth/AuthFactorEventRepositoryTest.php's
    // "minimises ip/user_agent instead of storing them verbatim" case inserts a
    // row and asserts on the persisted columns + metadata.
});

it('B7/PRIV-3: AnalyticsController rum() hashes the handle instead of logging it raw', function () {
    $src = readSource('app/Http/Controllers/Api/PublicSite/AnalyticsController.php');

    // Hashed from the RESOLVED site's subdomain since #W3-SEC-1 — the body's
    // handle is no longer trusted far enough to be the log's identity, let alone
    // its raw one.
    expect($src)
        ->not->toContain("'handle' => strtolower(\$handle),")
        ->and($src)->not->toContain("'handle' => strtolower(\$site->subdomain),")
        ->and($src)->toContain("'handle' => hash('sha256', strtolower(\$site->subdomain)),");
    // Behavioural coverage: tests/Feature/Analytics/PublicIngestHardeningTest.php's
    // "hashes the rum beacon handle instead of logging it raw" case drives the
    // rum() endpoint and asserts on the captured Log::info context.
});

it('#W1-SEC-4: MenuAiExtractor logs the exception CLASS on a transport throw, never getMessage()', function () {
    $src = readSource('app/Services/Platforms/MenuAiExtractor.php');

    // A caught Throwable's message can embed the full request URL — a signed
    // Google Places photo URL or a scraped website's PDF link. The log payload
    // must carry only the exception class; report($e) still gets full detail.
    expect($src)
        ->not->toContain("'error' => \$e->getMessage()")
        ->and(substr_count($src, "'error' => \$e::class"))->toBe(2);
    // Behavioural coverage: tests/Unit/Platforms/MenuAiExtractorFaultReportingTest.php's
    // "reports a transport-level throw with the exception CLASS in the log, not
    // a raw message" case drives the ocr() catch and asserts on Http::fake().
});

it('#W1-SEC-4: WebsiteMenuPdfScanJob hashes document_url instead of logging it raw', function () {
    $src = readSource('app/Jobs/Platforms/WebsiteMenuPdfScanJob.php');

    // documentUrl is a real hosted PDF link (a scraped website's document URL,
    // possibly carrying access-scoped query parameters) — never log it verbatim.
    expect($src)
        ->not->toContain("'document_url' => \$this->documentUrl")
        ->and(substr_count($src, 'document_url_hash'))->toBe(4);
    // Behavioural coverage: tests/Feature/Platforms/WebsiteMenuPdfScanJobTest.php
    // exercises this job end to end; the hash values themselves aren't asserted
    // there (this source guard is what pins the shape).
});
