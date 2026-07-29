<?php

use App\Routing\Iri;
use App\Routing\IriCanonicalizer;
use App\Routing\PublicSuffixList;
use App\Routing\SecretParams;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function iri(string $url): Iri
{
    return (new IriCanonicalizer(PublicSuffixList::instance()))->canonicalize($url);
}

// ── The spoof class the old [a-z.]+$ regexes admitted ────────────────────────

it('resolves a spoofed brand host to the attacker registrable domain, never the brand', function (string $url, string $registrable) {
    $i = iri($url);

    expect($i->isRoutable())->toBeTrue()
        ->and($i->registrableKey)->toBe($registrable);
})->with([
    ['https://opentable.evil.com/restaurant/profile/1', 'evil.com'],
    ['https://www.thefork.attacker.io/r/x', 'attacker.io'],
    ['https://instagram.phish.net/someone', 'phish.net'],
    ['https://deliveroo.evil.co.uk/menu/x', 'evil.co.uk'],
]);

it('discards userinfo — the host after @ is the only identity', function () {
    expect(iri('https://www.opentable.com.au@evil.com/restaurant/profile/1')->registrableKey)->toBe('evil.com')
        ->and(iri('https://instagram.com:pass@phish.io/user')->registrableKey)->toBe('phish.io');
});

it('rejects mixed-script confusable hosts but accepts legitimate IDN', function () {
    // xn--pple-43d = "аpple" (Cyrillic а + Latin pple).
    expect(iri('https://xn--pple-43d.com/x')->rejected)->toBe('idn-confusable')
        ->and(iri('https://münchen.de/x')->registrableKey)->toBe('xn--mnchen-3ya.de')
        ->and(iri('https://xn--mnchen-3ya.de/x')->rejected)->toBeNull();
});

// ── Rejection classes ────────────────────────────────────────────────────────

it('rejects by class', function (string $url, string $reason) {
    expect(iri($url)->rejected)->toBe($reason);
})->with([
    ['javascript:alert(1)', 'non-http-scheme'],
    ['data:text/html;base64,PHNjcmlwdD4=', 'non-http-scheme'],
    ['mailto:someone@example.com', 'non-http-scheme'],
    ['ftp://files.example.com/x', 'non-http-scheme'],
    ['https://dev-api.partna.au/api/internal/x', 'own-infra'],
    ['https://someone.partna.au/', 'own-infra'],
    ['https://abc.supabase.co/rest/v1/users', 'own-infra'],
    ['https://bucket.r2.cloudflarestorage.com/o', 'own-infra'],
    ['https://w.partna.workers.dev/', 'own-infra'],
    ['https://bit.ly/xyz', 'shortener'],
    ['https://linktr.ee/someone', 'shortener'],
    ['https://192.168.1.10/admin', 'ip-host'],
    ['https://com.au/', 'public-suffix-host'],
    ['', 'malformed'],
    ['https://', 'malformed'],
    ['https://..', 'malformed'],
]);

it('rejects an over-long url rather than parsing it', function () {
    expect(iri('https://example.com/'.str_repeat('a', 3000))->rejected)->toBe('too_long');
});

// ── Canonicalisation behaviour ───────────────────────────────────────────────

it('lowercases the host and preserves a meaningful port', function () {
    $i = iri('https://WWW.OPENTABLE.COM.AU:8443/restaurant/profile/12345');

    expect($i->host)->toBe('www.opentable.com.au')
        ->and($i->registrableKey)->toBe('opentable.com.au')
        ->and($i->port)->toBe(8443)
        ->and($i->canonical)->toContain(':8443');
});

it('strips tracking params but never identity params', function () {
    $i = iri('https://www.opentable.com.au/x?utm_source=news&fbclid=abc&igshid=z&rid=12345&owner=jo');

    expect($i->query)->toBe(['owner' => 'jo', 'rid' => '12345']);
});

it('normalises paths without corrupting encoded identity segments', function () {
    expect(iri('https://example.com//a///b/')->path)->toBe('/a/b')
        ->and(iri('https://example.com/a/./b/../c')->path)->toBe('/a/c')
        ->and(iri('https://example.com/')->path)->toBe('/')
        ->and(iri('https://example.com/caf%C3%A9-bar')->path)->toBe('/caf%C3%A9-bar');
});

it('accepts the paste shapes users actually produce', function () {
    expect(iri('instagram.com/someone')->registrableKey)->toBe('instagram.com')
        ->and(iri('//x.com/someone')->registrableKey)->toBe('x.com')
        ->and(iri('  https://x.com/someone  ')->registrableKey)->toBe('x.com');
});

// ── Catalog-aware host handling ──────────────────────────────────────────────

it('rewrites alias hosts to the registrable key whose detectors should run', function () {
    $i = iri('https://youtu.be/dQw4w9WgXcQ');

    expect($i->registrableKey)->toBe('youtube.com')
        ->and($i->aliasedFrom)->toBe('youtu.be');
});

it('treats multi-tenant suffixes as their own registrable boundary', function () {
    $i = iri('https://acme.myshopify.com/products/thing');

    expect($i->registrableKey)->toBe('acme.myshopify.com')
        ->and($i->tenantScoped)->toBeTrue();
});

it('exposes a subdomain only when it is meaningful', function () {
    expect(iri('https://www.example.com/x')->subdomain)->toBeNull()
        ->and(iri('https://shop.example.com/x')->subdomain)->toBe('shop')
        ->and(iri('https://example.com/x')->subdomain)->toBeNull();
});

// ── Messy real-world input (plan §2 robustness requirement) ──────────────────

it('strips locale and presentation params that do not change the resource', function () {
    expect(iri('https://www.instagram.com/someone/?hl=en')->query)->toBe([])
        ->and(iri('https://www.tiktok.com/@user?lang=en&is_from_webapp=1')->query)->toBe(['is_from_webapp' => '1'])
        ->and(iri('https://example.com/x?theme=dark&ref=newsletter&mode=grid')->query)->toBe([]);
});

it('keeps identity params that a capture rule may own', function () {
    $i = iri('https://www.opentable.com.au/booking?hl=en&rid=98765&utm_source=x');

    expect($i->query)->toBe(['rid' => '98765']);
});

it('drops presentation path suffixes only at the end of the path', function () {
    expect(iri('https://www.youtube.com/@somechannel/amp')->path)->toBe('/@somechannel')
        ->and(iri('https://www.facebook.com/someprofile/index.html')->path)->toBe('/someprofile')
        // A legitimate mid-path segment named "amp" survives.
        ->and(iri('https://example.com/amp/hour')->path)->toBe('/amp/hour');
});

it('survives copy-paste damage', function (string $input) {
    $i = iri($input);

    expect($i->isRoutable())->toBeTrue()
        ->and($i->registrableKey)->toBe('x.com')
        ->and($i->path)->toBe('/someuser');
})->with([
    '<https://x.com/someuser>',
    '<https://x.com/someuser>,',
    '"https://x.com/someuser"',
    'https://x.com/someuser.',
    'https://x.com/someuser)',
    '  https://x.com/someuser  ',
    'Check this out: https://x.com/someuser. Thanks!',
]);

it('rejoins a URL an email client split across lines', function () {
    expect(iri("https://www.instagram.com/some\nuser")->path)->toBe('/someuser');
});

it('produces a stable canonical string for equivalent inputs', function () {
    $a = iri('HTTP://WWW.Example.com/a/b/?utm_source=x&b=2&a=1');
    $b = iri('https://www.example.com/a/b?a=1&b=2');

    expect($a->canonical)->toBe($b->canonical);
});

// ── #SEC-1: secret-bearing query params never survive canonicalisation ──────

it('drops secret-bearing query params from the canonical form', function () {
    $i = iri('https://example.com/p?rid=123&token=eyJabc&utm_source=x');

    expect($i->query)->toBe(['rid' => '123'])
        ->and($i->canonical)->not->toContain('token')
        ->and($i->canonical)->not->toContain('eyJabc');
});

it('keeps replay honest — canonicalising a redacted raw_url matches the original canonical', function () {
    $input = 'https://www.opentable.com.au/restaurant/profile/12345?rid=12345&token=eyJhbGciOiJIUzI1NiJ9.aaa.bbb';

    $original = iri($input)->canonical;
    $redacted = iri((string) SecretParams::redactUrl($input))->canonical;

    expect($redacted)->toBe($original);
});
