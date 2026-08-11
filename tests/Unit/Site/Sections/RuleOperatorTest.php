<?php

use App\Site\Sections\RuleOperator;
use App\Site\Sections\RulePredicate;
use App\Site\Sections\SectionCandidates;

// Adding an operator touches four registries. Miss one and the failure is a
// 500 on a dashboard endpoint, not a red test — so pin all four here.

it('parses the upcoming_occurrence operator without throwing', function () {
    $predicate = RulePredicate::fromArray(['op' => 'upcoming_occurrence', 'values' => ['event']]);

    expect($predicate->operator)->toBe(RuleOperator::UpcomingOccurrence);
});

// THIS is what stops an unphrased operator 500ing a dashboard read.
// phrase() is an exhaustive match with no default (a fallback arm would be
// unreachable, and PHPStan fails the build on dead arms), so a case added
// without an arm throws UnhandledMatchError. Walking cases() here turns that
// into a red test in CI rather than a 500 in production — SectionResource
// calls phrase() on every serialise.
it('renders a phrase for every operator', function () {
    foreach (RuleOperator::cases() as $case) {
        expect($case->phrase())->toBeString()->not->toBe('');
    }
});

it('keeps the executed-operator list and the enum in lockstep', function () {
    expect(SectionCandidates::EXECUTED_OPERATORS)
        ->toEqualCanonicalizing(array_map(fn (RuleOperator $c) => $c->value, RuleOperator::cases()));
});
