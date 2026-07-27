<?php

use App\Content\Values\ColumnRule;
use App\Content\Values\Contribution;
use App\Content\Values\Override;
use App\Content\Values\ValueResolver;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function contribution(string $source, mixed $value, int $priority = 100, ?int $changedAt = null): Contribution
{
    return new Contribution($source, $value, $priority, $changedAt);
}

// ── The user outranks everything (C8) ───────────────────────────────────────

it('lets a manual override beat every source', function () {
    $resolved = (new ValueResolver)->resolve('f_text', 'headline', [
        contribution('spotify', 'Vendor Title', 500),
        contribution('apple', 'Other Title', 400),
    ], new Override('What The User Typed'));

    expect($resolved)->toBe('What The User Typed');
});

it('treats a null override as an explicit clear, not as absence', function () {
    // The distinction that makes "delete this field" possible at all: without
    // it, clearing a field would just let the next sync refill it.
    $resolved = (new ValueResolver)->resolve('f_text', 'body', [
        contribution('spotify', 'A description the user deleted'),
    ], new Override(null));

    expect($resolved)->toBeNull();
});

// ── Per-column rules ────────────────────────────────────────────────────────

it('picks the highest-priority source for identity-ish columns', function () {
    $resolved = (new ValueResolver)->resolve('f_text', 'headline', [
        contribution('import', 'Scraped Title', 10),
        contribution('manual', 'Real Title', 1000),
        contribution('spotify', 'Vendor Title', 500),
    ]);

    expect($resolved)->toBe('Real Title');
});

it('picks the longest value for prose columns', function () {
    // A fuller description is a better description; a longer TITLE usually is
    // not, which is why these are different rules rather than one heuristic.
    $resolved = (new ValueResolver)->resolve('f_text', 'body', [
        contribution('a', 'Short.', 900),
        contribution('b', 'A considerably fuller description of the thing.', 100),
    ]);

    expect($resolved)->toBe('A considerably fuller description of the thing.')
        ->and(ValueResolver::ruleFor('f_text', 'body'))->toBe(ColumnRule::Longest);
});

it('unions collection columns rather than picking a winner', function () {
    $resolved = (new ValueResolver)->resolve('f_authored', 'collaborators', [
        contribution('a', ['Ana', 'Ben']),
        contribution('b', ['Ben', 'Cy']),
    ]);

    expect($resolved)->toBe(['Ana', 'Ben', 'Cy']);
});

// ── Recency ─────────────────────────────────────────────────────────────────

it('prefers a meaningfully newer value for volatile columns', function () {
    $now = time();
    $resolved = (new ValueResolver)->resolve('f_channel', 'followers', [
        contribution('old', 1000, 500, $now - 86400 * 5),
        contribution('new', 1500, 100, $now),
    ]);

    expect($resolved)->toBe(1500);
});

it('does not let a barely-newer value displace the trusted source', function () {
    // Without the dwell window, a source re-publishing hourly would
    // permanently own every recency column it touches.
    $now = time();
    $resolved = (new ValueResolver)->resolve('f_channel', 'followers', [
        contribution('trusted', 1000, 900, $now - 3600),
        contribution('noisy', 9999, 100, $now),
    ]);

    expect($resolved)->toBe(1000);
});

it('ranks by change time, not fetch time', function () {
    // Two sources fetched seconds apart but whose CONTENT changed weeks apart
    // must resolve by the content, or every refresh reshuffles the answer.
    $now = time();
    $resolved = (new ValueResolver)->resolve('f_duration', 'seconds', [
        contribution('a', 200, 500, $now - 86400 * 30),
        contribution('b', 240, 100, $now - 86400 * 2),
    ]);

    expect($resolved)->toBe(240);
});

// ── Absence ─────────────────────────────────────────────────────────────────

it('ignores empty contributions rather than letting them win', function () {
    $resolved = (new ValueResolver)->resolve('f_text', 'headline', [
        contribution('empty', '', 1000),
        contribution('null', null, 900),
        contribution('real', 'Actual Title', 10),
    ]);

    expect($resolved)->toBe('Actual Title');
});

it('resolves to null when nobody has a value', function () {
    expect((new ValueResolver)->resolve('f_text', 'headline', []))->toBeNull();
});

it('falls back to source priority for a column with no declared rule', function () {
    expect(ValueResolver::ruleFor('f_place', 'venue_name'))->toBe(ColumnRule::SourcePriority);
});
