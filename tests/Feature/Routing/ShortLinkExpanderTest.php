<?php

// FI-3 (scan-refinement run, 2026-08-20): the shortener-expansion layer.
// Reproduced live before it existed: linktr.ee/samakhurst carried
// on.soundcloud.com/fh433tMk6lU9xgP3TM → soundcloud.com/sam-akhurst (the
// ARTIST profile), but with no expansion the short link fell to
// no-rule-matched and became a custom link card.

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\ShortLinkExpander;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    Cache::flush();
    // QUEUE_CONNECTION=sync — without this the content-class enrichment
    // fetch (F9) runs inline against the Http fake, fails, and soft-deletes
    // the very connection the assertions read.
    Queue::fake();
});

it('knows which hosts are short links', function () {
    $expander = app(ShortLinkExpander::class);

    expect($expander->isShort('https://on.soundcloud.com/AbC123'))->toBeTrue()
        ->and($expander->isShort('https://spotify.link/xYz'))->toBeTrue()
        ->and($expander->isShort('https://bit.ly/abc'))->toBeTrue()
        ->and($expander->isShort('https://soundcloud.com/sam-akhurst'))->toBeFalse()
        // Aggregators are pages to unroll, never redirects to follow.
        ->and($expander->isShort('https://linktr.ee/samakhurst'))->toBeFalse()
        ->and($expander->isShort('not a url'))->toBeFalse();
});

it('expands a short link and routes its real destination (the sammy.pdf shape)', function () {
    $pro = createTenant('shortlink-artist');

    Http::fake([
        'on.soundcloud.com/*' => Http::response('', 302, ['Location' => 'https://soundcloud.com/sam-akhurst?ref=clipboard']),
        'soundcloud.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://on.soundcloud.com/fh433tMk6lU9xgP3TM',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['routedTo']['surfaceKey'] ?? null)->toBe('soundcloud.player')
        ->and($out['routedTo']['identifier'] ?? null)->toBe('sam-akhurst');

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->firstOrFail();
    expect($connection->resource_id)->toBe('sam-akhurst');
});

it('rejects an unexpandable platform short code instead of minting a fake profile', function () {
    $pro = createTenant('shortlink-dead');

    Http::fake(['on.soundcloud.com/*' => Http::response('', 500)]);

    // Lowercase code — the exact shape that used to match the soundcloud
    // profile detector via the on.→soundcloud.com alias (confidence 75 ≥
    // auto 70) and mint an account named after the code.
    $out = app(LinkRoutingService::class)->route(
        'https://on.soundcloud.com/abc123xy',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('reject')
        ->and($out['blockReason'])->toBe('shortener');
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('caches an expansion so the preview → route pair fetches once', function () {
    $pro = createTenant('shortlink-cache');

    Http::fake([
        'on.soundcloud.com/*' => Http::response('', 302, ['Location' => 'https://soundcloud.com/sam-akhurst']),
        'soundcloud.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    $ctx = RoutingContext::forUser($pro, 'paste');
    app(LinkRoutingService::class)->preview('https://on.soundcloud.com/fh433tMk6lU9xgP3TM', $ctx);
    app(LinkRoutingService::class)->route('https://on.soundcloud.com/fh433tMk6lU9xgP3TM', $ctx);

    $shortHits = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'on.soundcloud.com'))
        ->count();
    expect($shortHits)->toBe(1);
});

it('keeps the canonicalizer rejecting platform short hosts when expansion is bypassed', function () {
    // Belt and braces: if a lane ever reaches canonicalize() without the
    // expander (or expansion failed), the host must reject as 'shortener' —
    // on. is a genuine soundcloud.com subdomain, so falling through would
    // evaluate the parent's detectors against an opaque code.
    $iri = app(IriCanonicalizer::class)->canonicalize('https://on.soundcloud.com/abc123xy');

    expect($iri->rejected)->toBe('shortener');
});

it('hands downstream consumers the EXPANDED url — probes never chase the short one (FI-9)', function () {
    // T4 live: route() expanded internally, but the importer's probe
    // dispatch and card fallback still carried the SHORT url — a tinyurl'd
    // page was probed as tinyurl.com (instant shortener reject, probe
    // wasted) and carded as "tinyurl.com" while its expansion routed
    // separately.
    $pro = createTenant('fi9-expanded-probe');

    Http::fake([
        'example.com/*' => Http::response('<a href="https://bit.ly/fi9code">My store</a>', 200, ['Content-Type' => 'text/html']),
        'bit.ly/*' => Http::response('', 302, ['Location' => 'https://example.org/shop']),
        'example.org/*' => Http::response('<html><body>shop</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/bio');

    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => $job->url === 'https://example.org/shop');
    Queue::assertNotPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'bit.ly'));
});
