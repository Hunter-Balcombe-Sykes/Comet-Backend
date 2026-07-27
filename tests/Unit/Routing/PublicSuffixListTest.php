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
