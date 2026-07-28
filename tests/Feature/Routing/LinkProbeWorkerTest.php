<?php

// The probe runtime (P8 blocker B1, plan §11). `probe_capability` has been
// compiled catalog metadata since the catalog landed and nothing ever executed
// one — a merchant's own-domain storefront had no way to be identified at all.
//
// What these pin is mostly about SPEND, because that is what makes a probe
// dangerous: an outbound request this backend makes on a stranger's say-so.
// The gate must refuse before it spends, the budget must bound what it spends,
// and a miss must cost the same as a hit next time round.

use App\Routing\Iri;
use App\Routing\IriCanonicalizer;
use App\Routing\Probes\LinkProbeWorker;
use App\Routing\Probes\ProbeBudget;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    app(ProbeBudget::class)->startRun();
});

function probeIri(string $url)
{
    return app(IriCanonicalizer::class)->canonicalize($url);
}

function shopifyMetaResponds(): void
{
    Http::fake([
        '*/meta.json' => Http::response(['id' => 4242, 'name' => 'The Store', 'currency' => 'AUD'], 200, ['Content-Type' => 'application/json']),
        '*' => Http::response('', 404),
    ]);
}

function probeWorker(): LinkProbeWorker
{
    return app(LinkProbeWorker::class);
}

it('identifies an own-domain storefront the projector cannot place', function () {
    // This is the capability that did not exist. `shop.example.com` carries no
    // host signal, so no detector can ever match it — only an answer from a
    // platform-only endpoint can.
    shopifyMetaResponds();

    $outcome = probeWorker()->probe(probeIri('https://example.com'), 'user-1');

    expect($outcome->isMatch())->toBeTrue()
        ->and($outcome->surfaceKey)->toBe('shopify.store')
        ->and($outcome->identifier)->toBe('4242')
        ->and($outcome->probe)->toBe('shopify_meta_json');
});

it('carries the probe response forward so the seeder need not re-fetch it', function () {
    shopifyMetaResponds();

    $outcome = probeWorker()->probe(probeIri('https://example.com'), 'user-evidence');

    expect($outcome->evidence['shop_name'])->toBe('The Store')
        ->and($outcome->evidence['currency'])->toBe('AUD');
});

it('reports a miss when no platform answers', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $outcome = probeWorker()->probe(probeIri('https://example.com'), 'user-miss');

    expect($outcome->isMatch())->toBeFalse()
        ->and($outcome->outcome)->toBe('miss');
});

it('tells "we did not look" apart from "it is not one"', function () {
    // Collapsing these is how a budget exhaustion silently becomes "this isn't
    // a shop" — and gets cached as one.
    Http::fake(['*' => Http::response('', 404)]);

    $missed = probeWorker()->probe(probeIri('https://example.com'), 'user-a');
    $refused = probeWorker()->probe(probeIri('https://api.partna.au/health'), 'user-a');

    expect($missed->outcome)->toBe('miss')
        ->and($refused->wasRefused())->toBeTrue()
        ->and($refused->reason)->toBe('own-infra');
});

// ── The gate, in cost order ──────────────────────────────────────────────────

it('never spends a request on our own infrastructure', function () {
    Http::fake();

    $outcome = probeWorker()->probe(probeIri('https://glncumufgaqcmqhzwrxm.supabase.co'), 'user-ssrf');

    expect($outcome->wasRefused())->toBeTrue()
        ->and($outcome->reason)->toBe('own-infra');
    Http::assertNothingSent();
});

it('refuses own infrastructure even on an Iri that skipped canonicalisation', function () {
    // The canonicaliser refuses these too. ProbeGate keeps its own copy of the
    // denylist so a caller that hand-builds an Iri cannot slip past by not
    // canonicalising — and reads it off SafeUrlFetcher so the two lists can
    // never drift apart in the permissive direction.
    Http::fake();

    $handBuilt = new Iri(
        raw: 'https://media.r2.dev/x',
        canonical: 'https://media.r2.dev/x',
        scheme: 'https',
        host: 'media.r2.dev',
        registrableKey: 'r2.dev',
        subdomain: 'media',
        path: '/x',
        query: [],
        port: null,
    );

    $outcome = probeWorker()->probe($handBuilt, 'user-handbuilt');

    expect($outcome->wasRefused())->toBeTrue()
        ->and($outcome->reason)->toBe('own-infra');
    Http::assertNothingSent();
});

it('never spends a request on an IP literal', function () {
    Http::fake();

    $outcome = probeWorker()->probe(probeIri('http://169.254.169.254/latest/meta-data'), 'user-metadata');

    expect($outcome->wasRefused())->toBeTrue();
    Http::assertNothingSent();
});

it('does not probe a URL a detector already places', function () {
    // acme.myshopify.com sits under a platform suffix and the projector places
    // it for free. Spending a request to re-confirm a detector is pure waste.
    Http::fake();

    $outcome = probeWorker()->probe(probeIri('https://acme.myshopify.com'), 'user-tenant');

    expect($outcome->wasRefused())->toBeTrue()
        ->and($outcome->reason)->toBe('already_matched');
    Http::assertNothingSent();
});

it('does not probe a deep page inside a site', function () {
    // Probe endpoints hang off the origin. A link to /blog/2026/a-post is a
    // page ON a site, not the site.
    Http::fake();

    $outcome = probeWorker()->probe(probeIri('https://example.com/blog/2026/a-post'), 'user-deep');

    expect($outcome->wasRefused())->toBeTrue()
        ->and($outcome->reason)->toBe('not_a_storefront_root');
    Http::assertNothingSent();
});

// ── Cooldown ─────────────────────────────────────────────────────────────────

it('keeps an answer rather than paying for it twice', function () {
    shopifyMetaResponds();

    $first = probeWorker()->probe(probeIri('https://example.com'), 'user-cache');
    Http::fake(['*' => Http::response('', 500)]);
    $second = probeWorker()->probe(probeIri('https://example.com'), 'user-cache');

    expect($first->isMatch())->toBeTrue()
        ->and($second->isMatch())->toBeTrue()
        ->and($second->identifier)->toBe($first->identifier);
});

it('caches a miss too', function () {
    // A miss that isn't cached is a URL re-probed on every scan of the same
    // page — the most expensive thing a scan can do, repeatedly, for nothing.
    Http::fake(['*' => Http::response('', 404)]);
    probeWorker()->probe(probeIri('https://example.com'), 'user-missrepeat');

    Http::fake();
    probeWorker()->probe(probeIri('https://example.com'), 'user-missrepeat');

    Http::assertNothingSent();
});

it('charges one probe for a URL two users paste', function () {
    // The outbound request is the cost, so the cooldown is keyed by URL and
    // never by user.
    shopifyMetaResponds();
    probeWorker()->probe(probeIri('https://example.com'), 'user-one');

    Http::fake();
    $second = probeWorker()->probe(probeIri('https://example.com'), 'user-two');

    expect($second->isMatch())->toBeTrue();
    Http::assertNothingSent();
});

// ── Budget ───────────────────────────────────────────────────────────────────

it('stops a single run from probing a whole page of links', function () {
    // The per-run counter lives on the ProbeBudget INSTANCE, so this also pins
    // the scoped container binding: unbound, the worker and the gate each get
    // their own budget object, the counters never meet, and this cap silently
    // never fires while the daily caps still look healthy.
    config()->set('partna.routing.probe.per_run_cap', 2);
    Http::fake(['*' => Http::response('', 404)]);

    $reasons = [];
    foreach (['a', 'b', 'c', 'd'] as $label) {
        $reasons[] = probeWorker()->probe(probeIri('https://example-'.$label.'.com'), 'user-run')->reason;
    }

    expect($reasons[0])->toBe('no_probe_matched')
        ->and($reasons[1])->toBe('no_probe_matched')
        ->and($reasons[2])->toBe('budget_per_run')
        ->and($reasons[3])->toBe('budget_per_run');
});

it('stops one account from making us hammer a third party', function () {
    config()->set('partna.routing.probe.user_daily_cap', 1);
    config()->set('partna.routing.probe.per_run_cap', 10);
    Http::fake(['*' => Http::response('', 404)]);

    probeWorker()->probe(probeIri('https://example-one.com'), 'user-greedy');
    $second = probeWorker()->probe(probeIri('https://example-two.com'), 'user-greedy');

    expect($second->wasRefused())->toBeTrue()
        ->and($second->reason)->toBe('budget_user_daily');
});

it('stops a runaway import from spending everyone else\'s allowance', function () {
    config()->set('partna.routing.probe.global_daily_cap', 1);
    config()->set('partna.routing.probe.per_run_cap', 10);
    Http::fake(['*' => Http::response('', 404)]);

    probeWorker()->probe(probeIri('https://example-one.com'), 'user-x');
    $other = probeWorker()->probe(probeIri('https://example-two.com'), 'user-y');

    expect($other->wasRefused())->toBeTrue()
        ->and($other->reason)->toBe('budget_global_daily');
});

it('costs nothing when a claim is rejected', function () {
    // Over ANY ceiling releases every counter the claim touched — otherwise a
    // rejected claim still burns the dimension that was not exhausted.
    config()->set('partna.routing.probe.user_daily_cap', 1);
    config()->set('partna.routing.probe.global_daily_cap', 10);
    config()->set('partna.routing.probe.per_run_cap', 10);
    Http::fake(['*' => Http::response('', 404)]);

    probeWorker()->probe(probeIri('https://example-one.com'), 'user-capped');
    probeWorker()->probe(probeIri('https://example-two.com'), 'user-capped');

    $global = (int) Cache::get(CacheKeyGenerator::routingProbeGlobalDaily(now()->format('Y-m-d')), 0);
    expect($global)->toBe(1);
});

it('still bounds a pre-account build that has no user to charge', function () {
    config()->set('partna.routing.probe.global_daily_cap', 1);
    config()->set('partna.routing.probe.per_run_cap', 10);
    Http::fake(['*' => Http::response('', 404)]);

    probeWorker()->probe(probeIri('https://example-one.com'), null);
    $second = probeWorker()->probe(probeIri('https://example-two.com'), null);

    expect($second->wasRefused())->toBeTrue()
        ->and($second->reason)->toBe('budget_global_daily');
});

// ── Failure isolation ────────────────────────────────────────────────────────

it('treats a probe that throws as a probe that missed', function () {
    // One platform's outage must never abort the cascade before the others
    // answer — that is how a Shopify blip would make every Woo store invisible.
    Http::fake(fn () => throw new RuntimeException('connection reset'));

    $outcome = probeWorker()->probe(probeIri('https://example.com'), 'user-throw');

    expect($outcome->outcome)->toBe('miss');
});

// ── The projection hand-off ──────────────────────────────────────────────────

it('hands back a projection the ordinary pipeline can place', function () {
    // A probe result IS a projection. That is what keeps tombstones,
    // capability gates and thresholds applying to a probed storefront exactly
    // as they apply to a pasted link — it travels the same path.
    shopifyMetaResponds();

    $projection = probeWorker()->probe(probeIri('https://example.com'), 'user-proj')->toProjection();

    expect($projection->matched())->toBeTrue()
        ->and($projection->surfaceKey)->toBe('shopify.store')
        // Above shop's auto threshold (75) even after the harvest penalty.
        ->and($projection->confidence)->toBeGreaterThan(85)
        ->and($projection->margin)->toBe(100);
});
