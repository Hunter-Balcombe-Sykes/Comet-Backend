<?php

use App\Support\BusinessName;

// Item 1b: the locality trim, pinned on the live cases that motivated it.

it('strips the listing own suburb off the end (the famished wolf shape)', function () {
    expect(BusinessName::trimLocality('The Famished Wolf Kensington', 'Kensington'))
        ->toBe(['name' => 'The Famished Wolf', 'rule' => 'locality-suffix']);
});

it('never touches a leading locality — that IS the identity', function () {
    expect(BusinessName::trimLocality('Kensington Street Social', 'Kensington'))
        ->toBe(['name' => 'Kensington Street Social', 'rule' => null]);
});

it('strips a delimited suffix made only of locality and sector words (akro shape)', function () {
    expect(BusinessName::trimLocality('AKRO STUDIO | ELSTERNWICK BARBERSHOP', 'Elsternwick'))
        ->toBe(['name' => 'AKRO STUDIO', 'rule' => 'delimited-suffix']);
});

it('keeps a delimited suffix that carries a brand-ish word', function () {
    expect(BusinessName::trimLocality('Wolf & Co | The Original Famished Kitchen', 'Kensington')['rule'])
        ->toBeNull();
});

it('returns the input untouched when no suburb is known and no generic suffix exists', function () {
    expect(BusinessName::trimLocality('The Famished Wolf Kensington', null))
        ->toBe(['name' => 'The Famished Wolf Kensington', 'rule' => null]);
});

it('handles multi-word suburbs', function () {
    expect(BusinessName::trimLocality('Salt Cutters St Kilda', 'St Kilda'))
        ->toBe(['name' => 'Salt Cutters', 'rule' => 'locality-suffix']);
});

it('never returns empty — a name that IS its suburb survives whole', function () {
    expect(BusinessName::trimLocality('Kensington', 'Kensington'))
        ->toBe(['name' => 'Kensington', 'rule' => null]);
});

it('output is always a subsequence of the input — trimming, never rewriting', function () {
    foreach ([
        ['The Famished Wolf Kensington', 'Kensington'],
        ['AKRO STUDIO | ELSTERNWICK BARBERSHOP', 'Elsternwick'],
        ['Plain Name', 'Nowhere'],
    ] as [$name, $suburb]) {
        $out = BusinessName::trimLocality($name, $suburb)['name'];
        expect(str_contains(strtolower($name), strtolower($out)) || $out === $name)->toBeTrue();
    }
});
