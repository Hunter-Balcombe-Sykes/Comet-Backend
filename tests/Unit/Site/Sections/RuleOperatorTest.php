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

it('renders a phrase for every operator', function () {
    foreach (RuleOperator::cases() as $case) {
        expect($case->phrase())->toBeString()->not->toBe('');
    }
});

it('keeps the executed-operator list and the enum in lockstep', function () {
    expect(SectionCandidates::EXECUTED_OPERATORS)
        ->toEqualCanonicalizing(array_map(fn (RuleOperator $c) => $c->value, RuleOperator::cases()));
});
