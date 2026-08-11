<?php

namespace App\Http\Requests\Api\User\Sections;

use App\Services\Content\KindRegistry;
use App\Site\Sections\RuleOperator;
use App\Site\Sections\SectionRule;
use Closure;

/**
 * Validation for the bounded section-rule DSL, shared by store and update.
 *
 * {@see SectionRule} already enforces the five-predicate ceiling and rejects
 * unknown operators by throwing — this turns that into a validation error the
 * user can read, rather than a 500. Values are checked per operator here
 * because SectionRule deliberately does not know what a kind is.
 */
trait SectionRuleRules
{
    /** Orderings the document builder understands. */
    private const ORDER_BY = ['recency', 'alphabetical', 'popularity', 'manual', 'occurrence'];

    /** Matches the operator set — see RuleOperator (nine, deliberately). */
    private const MAX_VALUES_PER_PREDICATE = 20;

    private function boundedRuleRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $rule = (array) $value;

            $unknown = array_diff(array_keys($rule), ['all']);
            if ($unknown !== []) {
                $fail('A section rule only supports an "all" list of conditions.');

                return;
            }

            try {
                $parsed = SectionRule::fromArray($rule);
            } catch (\InvalidArgumentException $e) {
                $fail($e->getMessage());

                return;
            }

            foreach ($parsed->predicates as $predicate) {
                if (count($predicate->values) > self::MAX_VALUES_PER_PREDICATE) {
                    $fail('A single condition may list at most '.self::MAX_VALUES_PER_PREDICATE.' values.');

                    return;
                }

                if ($predicate->values === []) {
                    $fail('The "'.$predicate->operator->value.'" condition needs at least one value.');

                    return;
                }

                if ($predicate->operator === RuleOperator::KindIs) {
                    foreach ($predicate->values as $kind) {
                        if (! KindRegistry::has($kind)) {
                            $fail('"'.$kind.'" is not a kind of content this platform has.');

                            return;
                        }
                    }
                }

                if ($predicate->operator === RuleOperator::PublishedWithin) {
                    $days = (int) $predicate->values[0];
                    if ($days < 1 || $days > 3650) {
                        $fail('A "published within" condition must be between 1 and 3650 days.');

                        return;
                    }
                }
            }
        };
    }
}
