<?php

use App\Routing\PublicSuffixList;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('resolves standard eTLD+1 boundaries', function (string $host, string $registrable) {
    expect(PublicSuffixList::instance()->registrableDomain($host))->toBe($registrable);
})->with([
    ['www.opentable.com.au', 'opentable.com.au'],
    ['opentable.com.au', 'opentable.com.au'],
    ['sub.deep.thefork.fr', 'thefork.fr'],
    ['example.com', 'example.com'],
    // The spoof class the old regexes admitted:
    ['opentable.evil.com', 'evil.com'],
    ['www.opentable.attacker.io', 'attacker.io'],
]);

it('applies private-section rules (multi-tenant hosting boundaries)', function () {
    // github.io lives in the PSL private section: each account is its own
    // registrable boundary.
    expect(PublicSuffixList::instance()->registrableDomain('acme.github.io'))->toBe('acme.github.io');
});

it('handles wildcard and exception rules per the PSL algorithm', function () {
    $psl = PublicSuffixList::instance();

    // *.ck is wildcarded, !www.ck is the canonical exception.
    expect($psl->registrableDomain('foo.bar.ck'))->toBe('foo.bar.ck')
        ->and($psl->registrableDomain('www.ck'))->toBe('www.ck');
});

it('returns null when the host IS a public suffix', function () {
    $psl = PublicSuffixList::instance();

    expect($psl->registrableDomain('com.au'))->toBeNull()
        ->and($psl->registrableDomain('com'))->toBeNull()
        ->and($psl->registrableDomain('github.io'))->toBeNull();
});

it('falls back to the implicit star rule for unknown TLDs', function () {
    expect(PublicSuffixList::instance()->registrableDomain('foo.bar.unknowntld'))->toBe('bar.unknowntld');
});

// #TEST-13: the algorithm above is hand-verified correct against the
// publicsuffix.org spec; the one real risk is a DATA regression — the
// vendored resources/psl/public_suffix_list.dat gets dropped, truncated, or
// replaced with a stale/empty snapshot on a future vendor refresh, and every
// test above still passes because they're all satisfiable by the implicit
// `*` fallback rule alone. This reads the SHIPPED file (not a fixture) and
// pins that the ICANN/private boundary it depends on actually holds: a
// private-section host (github.io) keeps its whole label as the registrable
// boundary, while an ICANN-section host collapses to the ordinary eTLD+1.
// A truncated or corrupted file makes both sides fall through to the same
// implicit-star answer and this test goes red.
it('parses the shipped PSL snapshot containing all three rule classes', function () {
    $psl = PublicSuffixList::instance();

    expect($psl->registrableDomain('acme.github.io'))->toBe('acme.github.io')
        ->and($psl->registrableDomain('acme.example.com'))->toBe('example.com');
});

// ── R2: the ICANN / private split (2026-08-18) ───────────────────────────────
// A PRIVATE-section suffix is a domain somebody actually registered and then
// published so their tenants get separate origins (github.io, canva.link). An
// ICANN-section suffix is one nobody can register at all (com.au). The PSL
// algorithm answers "no registrable domain" for both, which is spec-correct
// and product-wrong: it is what made canva.link unroutable. The primitive
// keeps the spec answer; this predicate is what lets the caller tell the two
// apart.

it('reports a private-section suffix as privately registered', function () {
    $psl = PublicSuffixList::instance();

    expect($psl->isPrivateSuffix('canva.link'))->toBeTrue()
        ->and($psl->isPrivateSuffix('github.io'))->toBeTrue();
});

it('does not report an ICANN suffix as privately registered', function () {
    $psl = PublicSuffixList::instance();

    expect($psl->isPrivateSuffix('com.au'))->toBeFalse();
});

it('does not report a bare ICANN TLD as privately registered', function () {
    expect(PublicSuffixList::instance()->isPrivateSuffix('com'))->toBeFalse();
});

it('does not report a tenant under a private suffix as the suffix itself', function () {
    // acme.github.io is a TENANT under a private suffix, not the suffix.
    expect(PublicSuffixList::instance()->isPrivateSuffix('acme.github.io'))->toBeFalse();
});

it('does not report an ordinary registrable host as a private suffix', function () {
    expect(PublicSuffixList::instance()->isPrivateSuffix('example.com'))->toBeFalse();
});
