<?php

// The bio-link gaps found in the 2026-08-28 cold-build run: four real
// accounts whose ONLY bio link produced nothing at all. Each case here is a
// URL taken from that run, not an invented shape.

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\LinkInBioDetector;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // The expander single-flights through CacheLockService, so it needs a live
    // cache; the routing/content tables are what the router writes through.
    setupRoutingTables();
    setupContentTables();
    Cache::flush();
    // No catch-all fake here on purpose: Http::fake() stubs are matched in
    // REGISTRATION order, so a '*' registered in beforeEach outranks every
    // per-test stub and quietly answers 200-with-no-Location — which reads
    // exactly like "this shortener does not expand".
});

function bioGapUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** What the catalog makes of a URL, by surface key (null when nothing matches). */
function bioGapSurface(string $url): ?string
{
    $iri = app(IriCanonicalizer::class)->canonicalize($url);
    $projection = app(LinkProjector::class)->project($iri);

    return $projection->matched() ? $projection->surfaceKey : null;
}

// ── shorteners in a bio (xia_tattoo) ─────────────────────────────────────────

it('expands a shortener in a bio and seeds the connection it hides', function () {
    // xia_tattoo's entire online presence sits behind one bit.ly. Before the
    // expansion moved ahead of classify(), this produced no connection, no
    // custom link and not even a routing observation — classify() returns
    // null for a shortener, so the router (which owns the expander) was never
    // reached.
    $user = bioGapUser('bgshort');

    Http::fake([
        'bit.ly/*' => Http::response('', 302, ['Location' => 'https://www.tiktok.com/@xiatattoo']),
        'tiktok.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://bit.ly/xiatattoolinks']);

    expect($result['unmatched'])->toBe([]);
    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    // resource_id on a social connection is the platform key, not the handle
    // — the username rides the payload (InstagramAutoSyncTest's own shape).
    expect($connection->platform)->toBe('tiktok');
});

it('unrolls the aggregator page a shortener resolves to', function () {
    Queue::fake();
    $user = bioGapUser('bgshortagg');

    Http::fake([
        'bit.ly/*' => Http::response('', 302, ['Location' => 'https://linktr.ee/xiatattoolinks']),
        'linktr.ee/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    app(InstagramAutoSync::class)->seed((string) $user->id, ['https://bit.ly/xiatattoolinks']);

    // The expanded destination is an aggregator, so it must reach the
    // unroller — the short form alone never could.
    Queue::assertPushed(LinkInBioScanJob::class);
});

it('leaves an ordinary bio link untouched by the expansion pass', function () {
    Queue::fake();
    Http::fake();
    $user = bioGapUser('bgplain');

    app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.tiktok.com/@plainhandle']);

    $connection = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();
    expect($connection->platform)->toBe('tiktok');
});

// ── link-in-bio hosts (igotyoubabeweddings) ──────────────────────────────────

it('recognises bio.site and its siblings as aggregator pages to unroll', function () {
    $detector = app(LinkInBioDetector::class);

    // bio.site sat one character from the already-listed bio.link and was
    // missed; igotyoubabeweddings' only bio link was one of these pages.
    expect($detector->matches('http://bio.site/igotyoubabeweddings/'))->toBeTrue()
        ->and($detector->matches('https://linkpop.com/acme'))->toBeTrue()
        ->and($detector->matches('https://flow.page/acme'))->toBeTrue()
        // Site builders are real sites, not link lists — the docblock's rule.
        ->and($detector->matches('https://acme.carrd.co'))->toBeFalse();
});

// ── booking hosts (theyogapeoplesydney) ──────────────────────────────────────

it('routes an Acuity short booking host, not just acuityscheduling.com', function () {
    // theyogapeoplelink.as.me — as.me is a suffix override, so the registrable
    // key is the TENANT host and the acuityscheduling.com detector never saw it.
    expect(bioGapSurface('https://theyogapeoplelink.as.me/'))->toBe('acuity.book')
        ->and(bioGapSurface('https://acuityscheduling.com/schedule.php?owner=12345678'))->toBe('acuity.book');
});

it('routes YouCanBook.me, whose suffix override had no brand behind it', function () {
    expect(bioGapSurface('https://acme.youcanbook.me/'))->toBe('youcanbookme.book')
        ->and(bioGapSurface('https://youcanbook.me/acme'))->toBe('youcanbookme.book');
});

// ── the harvester's own host tables (the second vocabulary) ──────────────────

it('classifies commercial brands as their own category, not a flat link', function () {
    // Two vocabularies decide a link's fate: the catalog (which routes it) and
    // WebsiteLinkHarvester's hand tables (which decide WHICH KIND of card it
    // becomes). A catalog brand missing from the tables falls through to
    // classifyFromCatalog's flat 'link' answer and seeds a plain link card
    // instead of a booking/reservation/ordering provider card — the failure the
    // Tock and plan-03 batch-5 comments in that file already record.
    //
    // bodytuneperth is the proof the lanes differ: their Cliniko booking link
    // connected with ZERO routing observations, because the tables seeded it
    // directly. theyogapeoplesydney's Acuity link, known only to the catalog,
    // did not.
    $harvester = app(WebsiteLinkHarvester::class);

    $category = fn (string $url): ?string => $harvester->classify($url)['category'] ?? null;

    expect($category('https://theyogapeoplelink.as.me/'))->toBe('booking')
        ->and($category('https://acme.youcanbook.me/'))->toBe('booking')
        ->and($category('https://venue.ink/@someartist'))->toBe('booking')
        ->and($category('https://vouchers.obeeapp.com/some-venue/gift-voucher'))->toBe('reservations')
        ->and($category('https://w.abacus.co/store/1234567/giftcards/landingPage'))->toBe('online-ordering');
});

it('identifies a YouTube channel pasted with a stale /channel/ prefix', function () {
    // youtube.com/channel/@handle matched neither rule: the /channel/ one
    // wants a UC id, the /@ one is anchored at the path root. djhellraiser's
    // bio carried exactly this and their channel went unidentified.
    expect(bioGapSurface('https://youtube.com/channel/@djhellraiser303'))->toBe('youtube.channel')
        ->and(bioGapSurface('https://www.youtube.com/@djhellraiser303'))->toBe('youtube.channel')
        ->and(bioGapSurface('https://youtube.com/channel/UCabcdefghijklmnopqrstuv'))->toBe('youtube.channel');
});

it('identifies a bare wa.me phone link, the shape its own canonicalUrl emits', function () {
    // Only links unrolled from an AGGREGATOR reach the catalog — a wa.me link
    // straight out of an Instagram bio is seeded by the harvester and never
    // gets here, which is why this survived so long. finderseekerphotography's
    // bio.site WhatsApp link noted as no-rule-matched while two other accounts'
    // identical-shaped links connected through the other lane.
    expect(bioGapSurface('https://wa.me/61421637062'))->toBe('whatsapp.chat')
        // Leading '+' is how people write an international number.
        ->and(bioGapSurface('https://wa.me/+61421637062'))->toBe('whatsapp.chat')
        // The two entry-point shapes that already worked must keep working.
        ->and(bioGapSurface('https://wa.me/message/ABCDEFGHIJK'))->toBe('whatsapp.chat')
        ->and(bioGapSurface('https://api.whatsapp.com/send?phone=61421637062'))->toBe('whatsapp.chat')
        // Not a phone number — must NOT be claimed as a chat link.
        ->and(bioGapSurface('https://wa.me/12'))->toBeNull()
        ->and(bioGapSurface('https://wa.me/abcdefghij'))->toBeNull();
});
