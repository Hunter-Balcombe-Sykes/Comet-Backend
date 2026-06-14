<?php

use App\Http\Middleware\Logging\LogLeadRateLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();

    attachTestSchemas();

    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS analytics.lead_submissions');
    DB::connection('pgsql')->statement('CREATE TABLE analytics.lead_submissions (
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

    $this->middleware = new LogLeadRateLimits;
});

function makeLeadRequest(array $overrides = []): Request
{
    $headers = array_merge([
        'HTTP_REFERER' => 'https://example.com/path?utm_source=newsletter&email=leak@example.com',
        'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ], $overrides['server'] ?? []);

    $request = Request::create($overrides['uri'] ?? '/api/public/customers', 'POST', [], [], [], $headers);
    $request->server->set('REMOTE_ADDR', $overrides['ip'] ?? '203.0.113.10');

    return $request;
}

// --- LIFE-1 / SCALE-1: handle() must always pass response through unchanged ---

it('handle() is a passthrough — does not write to DB before terminate()', function () {
    $request = makeLeadRequest();
    $response = new IlluminateResponse('throttled', 429);

    $returned = $this->middleware->handle($request, fn () => $response);

    expect($returned)->toBe($response);
    expect(DB::connection('pgsql')->table('analytics.lead_submissions')->count())->toBe(0);
});

it('terminate() does nothing for non-429 responses', function () {
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('ok', 200));

    expect(DB::connection('pgsql')->table('analytics.lead_submissions')->count())->toBe(0);
});

// --- LIFE-2: dedup retry bursts via Cache::add ---

it('terminate() writes one row on a 429 response', function () {
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));

    $rows = DB::connection('pgsql')->table('analytics.lead_submissions')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->outcome)->toBe('rate_limited');
});

it('dedups duplicate 429s from the same source within the TTL', function () {
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));

    expect(DB::connection('pgsql')->table('analytics.lead_submissions')->count())->toBe(1);
});

it('does not dedup distinct sources (different IPs)', function () {
    $this->middleware->terminate(makeLeadRequest(['ip' => '203.0.113.10']), new IlluminateResponse('throttled', 429));
    $this->middleware->terminate(makeLeadRequest(['ip' => '198.51.100.20']), new IlluminateResponse('throttled', 429));

    expect(DB::connection('pgsql')->table('analytics.lead_submissions')->count())->toBe(2);
});

// --- SEC-3: Referer is sanitized — query string stripped, length capped ---

it('strips the query string from the stored Referer', function () {
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));

    $row = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($row->referrer)->toBe('https://example.com/path');
    expect($row->referrer)->not->toContain('utm_source');
    expect($row->referrer)->not->toContain('email=leak@example.com');
});

it('caps the Referer at 512 characters', function () {
    $longPath = '/'.str_repeat('a', 600);
    $this->middleware->terminate(
        makeLeadRequest(['server' => ['HTTP_REFERER' => 'https://example.com'.$longPath]]),
        new IlluminateResponse('throttled', 429)
    );

    $row = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect(strlen($row->referrer))->toBeLessThanOrEqual(512);
});

it('caps the stored user_agent at 256 characters (PRIV-6)', function () {
    $this->middleware->terminate(
        makeLeadRequest(['server' => ['HTTP_USER_AGENT' => str_repeat('U', 400)]]),
        new IlluminateResponse('throttled', 429)
    );

    $row = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect(strlen($row->user_agent))->toBe(256);
});

it('stores null for a missing or unparseable Referer', function () {
    $this->middleware->terminate(
        makeLeadRequest(['server' => ['HTTP_REFERER' => '']]),
        new IlluminateResponse('throttled', 429)
    );

    $row = DB::connection('pgsql')->table('analytics.lead_submissions')->first();
    expect($row->referrer)->toBeNull();
});

// --- LIFE-1: terminate() swallows DB errors so they can never reach the client ---

it('swallows DB exceptions in terminate() and logs a warning breadcrumb', function () {
    // Drop the analytics table to force the insert to throw.
    DB::connection('pgsql')->statement('DROP TABLE analytics.lead_submissions');

    Log::spy();

    // Must not throw — the response was already flushed before terminate fired.
    $this->middleware->terminate(makeLeadRequest(), new IlluminateResponse('throttled', 429));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'lead.rate_limit_log_failed'
            && array_key_exists('exception', $ctx)
            && array_key_exists('path', $ctx))
        ->once();
});
