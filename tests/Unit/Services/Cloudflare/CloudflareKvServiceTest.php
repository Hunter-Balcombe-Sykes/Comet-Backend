<?php

use App\Services\Cloudflare\CloudflareKvService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Helper to configure the service with test credentials.
function configureKv(): void
{
    Config::set('services.cloudflare.account_id', 'acct123');
    Config::set('services.cloudflare.kv_namespace_id', 'ns456');
    Config::set('services.cloudflare.api_token', 'tok');
}

// --- bulkPut ---

it('no-ops on empty entries array — no HTTP sent (SCALE-6)', function () {
    configureKv();
    Http::fake();

    (new CloudflareKvService)->bulkPut([]);

    Http::assertNothingSent();
});

it('no-ops when unconfigured even with entries (SCALE-6)', function () {
    Config::set('services.cloudflare.account_id', '');
    Config::set('services.cloudflare.kv_namespace_id', '');
    Config::set('services.cloudflare.api_token', '');
    Http::fake();

    (new CloudflareKvService)->bulkPut([
        ['key' => 'k', 'value' => ['type' => 'alias', 'redirect' => 'https://x.partna.au'], 'expiration_ttl' => 3600],
    ]);

    Http::assertNothingSent();
});

it('PUTs to the bulk endpoint with Bearer auth and JSON-string values (SCALE-6)', function () {
    configureKv();
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflareKvService)->bulkPut([
        ['key' => 'oldhandle', 'value' => ['type' => 'alias', 'redirect' => 'https://cur.partna.au'], 'expiration_ttl' => 7200],
    ]);

    Http::assertSent(function ($req) {
        $expectedUrl = 'https://api.cloudflare.com/client/v4/accounts/acct123/storage/kv/namespaces/ns456/bulk';

        if ($req->url() !== $expectedUrl) {
            return false;
        }
        if ($req->method() !== 'PUT') {
            return false;
        }
        if (! $req->hasHeader('Authorization', 'Bearer tok')) {
            return false;
        }

        $body = json_decode((string) $req->body(), true);
        if (! is_array($body) || count($body) !== 1) {
            return false;
        }

        $entry = $body[0];

        // Value must be a JSON-encoded STRING (matching single put() idiom).
        return $entry['key'] === 'oldhandle'
            && $entry['value'] === json_encode(['type' => 'alias', 'redirect' => 'https://cur.partna.au'])
            && $entry['expiration_ttl'] === 7200
            && $entry['base64'] === false;
    });
});

it('omits expiration_ttl from the payload when null (permanent entry, matching put() idiom) (SCALE-6)', function () {
    configureKv();
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflareKvService)->bulkPut([
        ['key' => 'permkey', 'value' => ['type' => 'alias', 'redirect' => 'https://h.partna.au'], 'expiration_ttl' => null],
    ]);

    Http::assertSent(function ($req) {
        $body = json_decode((string) $req->body(), true);
        $entry = $body[0];

        // expiration_ttl must NOT be present when null.
        return ! array_key_exists('expiration_ttl', $entry)
            && $entry['key'] === 'permkey'
            && $entry['base64'] === false;
    });
});

it('sends two requests when entries exceed 10 000 (chunking) (SCALE-6)', function () {
    configureKv();
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // Build 10 001 entries — should be split into chunk of 10 000 + chunk of 1.
    $entries = [];
    for ($i = 0; $i < 10001; $i++) {
        $entries[] = [
            'key' => "key{$i}",
            'value' => ['type' => 'alias', 'redirect' => 'https://h.partna.au'],
            'expiration_ttl' => 3600,
        ];
    }

    (new CloudflareKvService)->bulkPut($entries);

    Http::assertSentCount(2);
});

it('sends a single request for exactly 10 000 entries (no unnecessary chunk split) (SCALE-6)', function () {
    configureKv();
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $entries = [];
    for ($i = 0; $i < 10000; $i++) {
        $entries[] = [
            'key' => "key{$i}",
            'value' => ['type' => 'alias', 'redirect' => 'https://h.partna.au'],
            'expiration_ttl' => 3600,
        ];
    }

    (new CloudflareKvService)->bulkPut($entries);

    Http::assertSentCount(1);
});

it('mixed null and non-null expiration_ttl entries: only sets ttl on relevant entries (SCALE-6)', function () {
    configureKv();
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflareKvService)->bulkPut([
        ['key' => 'withttl', 'value' => ['type' => 'alias', 'redirect' => 'https://h.partna.au'], 'expiration_ttl' => 1800],
        ['key' => 'nottl', 'value' => ['type' => 'alias', 'redirect' => 'https://h.partna.au'], 'expiration_ttl' => null],
    ]);

    Http::assertSent(function ($req) {
        $body = json_decode((string) $req->body(), true);

        if (count($body) !== 2) {
            return false;
        }

        $byKey = array_column($body, null, 'key');

        return isset($byKey['withttl']['expiration_ttl'])
            && $byKey['withttl']['expiration_ttl'] === 1800
            && ! array_key_exists('expiration_ttl', $byKey['nottl']);
    });
});
