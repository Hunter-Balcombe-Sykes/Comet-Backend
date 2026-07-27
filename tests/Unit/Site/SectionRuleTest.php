<?php

use App\Site\Sections\RuleOperator;
use App\Site\Sections\RulePredicate;
use App\Site\Sections\SectionRule;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('renders an empty rule as everything', function () {
    expect((new SectionRule)->toSentence())->toBe('Everything');
});

it('renders a single condition as a readable sentence', function () {
    $rule = new SectionRule([
        new RulePredicate(RuleOperator::KindIs, ['menu_item']),
    ]);

    expect($rule->toSentence())->toBe('Anything that is a menu item');
});

it('joins alternatives with or, and conditions with and', function () {
    $rule = new SectionRule([
        new RulePredicate(RuleOperator::KindIs, ['track', 'release']),
        new RulePredicate(RuleOperator::HasFacet, ['f_embed']),
    ]);

    // Facet keys get real words: "has f embed" is not a sentence to show anyone.
    expect($rule->toSentence())->toBe('Anything that is a track or release and has a player');
});

it('renders a negated condition without a double negative', function () {
    $rule = new SectionRule([
        new RulePredicate(RuleOperator::FromSource, ['instagram'], negated: true),
    ]);

    expect($rule->toSentence())->toBe('Anything that comes from not instagram');
});

it('renders a time window in days rather than raw numbers', function () {
    expect((new SectionRule([new RulePredicate(RuleOperator::PublishedWithin, ['30'])]))->toSentence())
        ->toBe('Anything that was published within the last 30 days')
        ->and((new SectionRule([new RulePredicate(RuleOperator::PublishedWithin, ['1'])]))->toSentence())
        ->toBe('Anything that was published within the last day');
});

it('refuses a rule with more conditions than a person can read', function () {
    // The bound is what keeps a rule explainable; removing it would quietly
    // turn this into a query language.
    $tooMany = array_fill(0, 6, new RulePredicate(RuleOperator::KindIs, ['video']));

    expect(fn () => new SectionRule($tooMany))
        ->toThrow(InvalidArgumentException::class, 'at most 5 conditions');
});

it('rejects an operator outside the closed set', function () {
    expect(fn () => RulePredicate::fromArray(['op' => 'raw_sql', 'values' => ['1=1']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown rule operator');
});

it('round-trips through its stored form', function () {
    $rule = new SectionRule([
        new RulePredicate(RuleOperator::KindIs, ['event']),
        new RulePredicate(RuleOperator::TaggedWith, ['live'], negated: true),
    ]);

    $restored = SectionRule::fromArray($rule->toArray());

    expect($restored->toArray())->toBe($rule->toArray())
        ->and($restored->toSentence())->toBe($rule->toSentence());
});
