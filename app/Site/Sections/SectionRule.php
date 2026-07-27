<?php

namespace App\Site\Sections;

/**
 * A section's membership rule: at most five predicates, ANDed. The bound is
 * the point — it is what makes the rule renderable as one English sentence,
 * validatable, and explainable per candidate in the trace endpoint. A rule a
 * user cannot read is a rule they cannot fix.
 */
final readonly class SectionRule
{
    private const MAX_PREDICATES = 5;

    /** @param list<RulePredicate> $predicates */
    public function __construct(public array $predicates = [])
    {
        if (count($predicates) > self::MAX_PREDICATES) {
            throw new \InvalidArgumentException(
                'A section rule may have at most '.self::MAX_PREDICATES.' conditions; got '.count($predicates).'.'
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            fn (array $p) => RulePredicate::fromArray($p),
            array_values((array) ($data['all'] ?? [])),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['all' => array_map(fn (RulePredicate $p) => $p->toArray(), $this->predicates)];
    }

    public function isEmpty(): bool
    {
        return $this->predicates === [];
    }

    /**
     * The rule as a sentence — this is the actual UI copy, not a debug
     * rendering, which is why it lives with the rule rather than in a view.
     */
    public function toSentence(): string
    {
        if ($this->isEmpty()) {
            return 'Everything';
        }

        $parts = [];
        foreach ($this->predicates as $predicate) {
            $values = $this->humaniseValues($predicate);
            $phrase = $predicate->operator->phrase();
            $parts[] = $predicate->negated
                ? "{$phrase} not {$values}"
                : "{$phrase} {$values}";
        }

        return 'Anything that '.$this->joinWithAnd($parts);
    }

    /**
     * Facet keys are storage names, not words: "has f embed" is not a
     * sentence anyone should be shown. Everything else humanises by simply
     * unslugging, which reads correctly for kinds and tags.
     *
     * @var array<string, string>
     */
    private const FACET_LABELS = [
        'f_embed' => 'a player',
        'f_playable' => 'audio',
        'f_occurrence' => 'a date',
        'f_place' => 'a location',
        'f_rated' => 'a rating',
        'f_review' => 'review text',
        'f_channel' => 'channel details',
        'f_file' => 'a file',
        'f_action' => 'an action',
        'offers' => 'a price',
        'item_media' => 'an image',
    ];

    private function humaniseValues(RulePredicate $predicate): string
    {
        $values = $predicate->values;

        if ($predicate->operator === RuleOperator::PublishedWithin) {
            $days = (int) ($values[0] ?? 0);

            return $days === 1 ? 'the last day' : "the last {$days} days";
        }

        $readable = array_map(
            fn (string $v) => $predicate->operator === RuleOperator::HasFacet
                ? (self::FACET_LABELS[$v] ?? str_replace('_', ' ', $v))
                : str_replace('_', ' ', $v),
            $values,
        );

        return $this->joinWithOr($readable);
    }

    /** @param list<string> $parts */
    private function joinWithAnd(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }

    /** @param list<string> $parts */
    private function joinWithOr(array $parts): string
    {
        if ($parts === []) {
            return 'anything';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' or '.$last;
    }
}
