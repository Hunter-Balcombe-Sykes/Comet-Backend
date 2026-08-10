<?php

use App\Services\Profile\PersonNameParser;

it('splits a real person name into first and last', function () {
    expect(PersonNameParser::parse('SIMON DOYLE | Barber & Educator'))
        ->toBe(['displayName' => 'SIMON DOYLE | Barber & Educator', 'firstName' => 'SIMON', 'lastName' => 'DOYLE']);
});

it('keeps the full raw string as displayName', function () {
    expect(PersonNameParser::parse('Jane Smith – Stylist')['displayName'])->toBe('Jane Smith – Stylist');
});

it('returns a null lastName for a single token', function () {
    expect(PersonNameParser::parse('Cher'))
        ->toBe(['displayName' => 'Cher', 'firstName' => 'Cher', 'lastName' => null]);
});

it('strips the tagline after each supported separator', function (string $input) {
    $parsed = PersonNameParser::parse($input);
    expect($parsed['firstName'])->toBe('Ana')->and($parsed['lastName'])->toBe('Ruiz');
})->with([
    'Ana Ruiz | Colourist',
    'Ana Ruiz – Colourist',
    'Ana Ruiz — Colourist',
    'Ana Ruiz • Colourist',
    'Ana Ruiz|Colourist',
]);

it('takes the last token as the surname when there are middle names', function () {
    expect(PersonNameParser::parse('Mary Jane Watson')['lastName'])->toBe('Watson');
});

it('handles an empty string without error', function () {
    expect(PersonNameParser::parse(''))
        ->toBe(['displayName' => '', 'firstName' => '', 'lastName' => null]);
});

it('collapses repeated whitespace rather than emitting empty tokens', function () {
    expect(PersonNameParser::parse('Leo    Vance'))
        ->toBe(['displayName' => 'Leo    Vance', 'firstName' => 'Leo', 'lastName' => 'Vance']);
});
