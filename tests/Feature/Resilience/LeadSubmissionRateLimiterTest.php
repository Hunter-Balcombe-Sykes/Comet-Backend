<?php

use App\Http\Middleware\Throttle\LeadSubmissionRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The degraded-mode counter. When Redis is unreachable the `leads` limiter
 * counts rows in analytics.lead_submissions rather than opening — see
 * docs/superpowers/specs/2026-08-06-enquiry-path-redis-resilience-design.md.
 */
beforeEach(function () {
    // SQLite has no schema support; ATTACH DATABASE per tests/Pest.php's
    // documented pattern before CREATE TABLE analytics.* can resolve.
    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        occurred_at TEXT NULL,
        subdomain TEXT NULL,
        site_id TEXT NULL,
        user_id TEXT NULL,
        customer_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        outcome TEXT NULL,
        form_started_at_ms INTEGER NULL
    )');
    DB::connection('pgsql')->table('analytics.lead_submissions')->delete();

    config([
        'partna.throttle.leads_degraded_per_minute_ip' => 3,
        'partna.throttle.leads_degraded_per_minute_subdomain' => 5,
    ]);
});

/** Build a request shaped like a real enquiry POST from a tenant mini-site. */
function degradedLeadRequest(string $subdomain = 'counter-site', string $ip = '203.0.113.7'): Request
{
    $request = Request::create(
        'https://'.$subdomain.'.'.config('partna.public_domain').'/api/public/enquiry',
        'POST',
        [],
        [],
        [],
        ['REMOTE_ADDR' => $ip],
    );
    $request->headers->set('X-Site-Subdomain', $subdomain);

    return $request;
}

/**
 * Insert a lead row the way the controllers' logLead() does.
 *
 * Named seedDegradedLeadSubmission (not the brief's seedLeadSubmission) —
 * tests/Unit/Models/CustomerRedactTest.php already declares a global
 * seedLeadSubmission(?string $customerId, array $overrides = []) with a
 * different signature. Pest test files share one global function namespace,
 * so reusing the name would be a fatal redeclare across the suite.
 */
function seedDegradedLeadSubmission(array $overrides = []): void
{
    DB::connection('pgsql')->table('analytics.lead_submissions')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'occurred_at' => now()->toDateTimeString(),
        'subdomain' => 'counter-site',
        'ip_hash' => hash_hmac('sha256', '203.0.113.7', config('app.key')),
        'outcome' => 'created',
    ], $overrides));
}

it('admits a request when no recent submissions exist', function () {
    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeFalse();
});

it('admits a request below the per-IP limit', function () {
    seedDegradedLeadSubmission();
    seedDegradedLeadSubmission();

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeFalse();
});

it('rejects a request at the per-IP limit', function () {
    seedDegradedLeadSubmission();
    seedDegradedLeadSubmission();
    seedDegradedLeadSubmission();

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeTrue();
});

it('rejects everything when the degraded limit is clamped to 0', function () {
    // Finding 4 (2026-08-06 final review): `> 0` treated a limit of 0 as "no
    // limit configured" and skipped the bucket entirely, admitting every
    // request — the opposite of `Limit::perMinute(0)`'s healthy-mode meaning
    // and the exact opposite of the "clamp mid-incident" escape hatch the
    // config comment promises. `>= 0` fixes it: count is never negative, so a
    // limit of 0 always blocks, with zero prior submissions required.
    config(['partna.throttle.leads_degraded_per_minute_ip' => 0]);

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeTrue();
});

it('ignores submissions older than the window', function () {
    seedDegradedLeadSubmission(['occurred_at' => now()->subMinutes(2)->toDateTimeString()]);
    seedDegradedLeadSubmission(['occurred_at' => now()->subMinutes(2)->toDateTimeString()]);
    seedDegradedLeadSubmission(['occurred_at' => now()->subMinutes(2)->toDateTimeString()]);

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeFalse();
});

it('does not count rate_limited rows toward the window', function () {
    // Laravel's ThrottleRequests does NOT hit() once already over the limit, so
    // a rejected request never extends the Redis window. LogLeadRateLimits DOES
    // write an outcome='rate_limited' row on every 429. Counting those would
    // make the Postgres fallback stricter than Redis — an over-limit client's
    // own 429s would keep them locked out indefinitely.
    seedDegradedLeadSubmission(['outcome' => 'rate_limited']);
    seedDegradedLeadSubmission(['outcome' => 'rate_limited']);
    seedDegradedLeadSubmission(['outcome' => 'rate_limited']);

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeFalse();
});

it('counts non-created outcomes such as honeypot and too_fast', function () {
    // Bot attempts are exactly what the limiter exists to bound; only the
    // limiter's own rejections are excluded.
    seedDegradedLeadSubmission(['outcome' => 'honeypot']);
    seedDegradedLeadSubmission(['outcome' => 'too_fast']);
    seedDegradedLeadSubmission(['outcome' => 'site_not_found']);

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeTrue();
});

it('rejects at the per-subdomain limit even from distinct IPs', function () {
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $ip) {
        seedDegradedLeadSubmission(['ip_hash' => hash_hmac('sha256', $ip, config('app.key'))]);
    }

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeTrue();
});

it('scopes the subdomain bucket to one site', function () {
    foreach (range(1, 5) as $n) {
        seedDegradedLeadSubmission([
            'subdomain' => 'other-site',
            'ip_hash' => hash_hmac('sha256', '198.51.100.'.$n, config('app.key')),
        ]);
    }

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest()))->toBeFalse();
});

it('hashes the IP the same way logLead does', function () {
    // The counter is only correct if it reads what the controller writes.
    // HashesClientData::hashIp is hash_hmac('sha256', $ip, config('app.key'))
    // over $request->ip() — and bootstrap/app.php:74 sets trustProxies(at: '*'),
    // so $request->ip() already resolves the real client IP from X-Forwarded-For.
    seedDegradedLeadSubmission(['ip_hash' => hash_hmac('sha256', '203.0.113.7', config('app.key'))]);
    seedDegradedLeadSubmission(['ip_hash' => hash_hmac('sha256', '203.0.113.7', config('app.key'))]);
    seedDegradedLeadSubmission(['ip_hash' => hash_hmac('sha256', '203.0.113.7', config('app.key'))]);

    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest(ip: '203.0.113.7')))->toBeTrue();
    expect(app(LeadSubmissionRateLimiter::class)->exceeded(degradedLeadRequest(ip: '203.0.113.99')))->toBeFalse();
});
