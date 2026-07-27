<?php

namespace App\Content\Values;

/**
 * Resolves per-source facet rows into the ONE value the sitepage shows
 * (plan §6). Pure, like the identity resolver, and for the same reason: the
 * answer must be re-derivable rather than accumulated.
 *
 * Precedence, absolute and in this order:
 *
 *   1. a MANUAL OVERRIDE      — the user typed this; nothing outranks it (C8)
 *   2. the column's own rule  — recency / priority / longest / union
 *   3. source priority        — the tiebreak inside a rule
 *
 * A NULL override is not "no override": it is the user explicitly clearing a
 * field, which must beat every source that would otherwise fill it.
 */
class ValueResolver
{
    /**
     * Recency only wins if the challenger is meaningfully newer. Without this
     * a source that re-publishes unchanged content every hour would
     * permanently own every field it touches.
     */
    private const RECENCY_DWELL_HOURS = 24;

    /** @var array<string, ColumnRule> "facet.column" => rule */
    private const COLUMN_RULES = [
        'f_text.headline' => ColumnRule::SourcePriority,
        'f_text.body' => ColumnRule::Longest,
        'f_text.summary' => ColumnRule::Longest,
        'f_link.url' => ColumnRule::SourcePriority,
        'f_link.canonical_url' => ColumnRule::SourcePriority,
        'f_authored.creator' => ColumnRule::SourcePriority,
        'f_authored.collaborators' => ColumnRule::Union,
        'f_catalog.release_type' => ColumnRule::SourcePriority,
        'f_catalog.isrc' => ColumnRule::SourcePriority,
        'f_catalog.gtin' => ColumnRule::SourcePriority,
        'f_duration.seconds' => ColumnRule::Recency,
        'f_published.published_from' => ColumnRule::Recency,
        'f_channel.followers' => ColumnRule::Recency,
        'f_channel.is_live' => ColumnRule::Recency,
        'f_rated.rating' => ColumnRule::Recency,
        'f_rated.ratings_count' => ColumnRule::Recency,
    ];

    public static function ruleFor(string $facet, string $column): ColumnRule
    {
        // An undeclared column falls to source priority: the conservative
        // choice, since it never lets a noisy source overwrite a trusted one.
        return self::COLUMN_RULES[$facet.'.'.$column] ?? ColumnRule::SourcePriority;
    }

    /**
     * @param  list<Contribution>  $contributions  one per source that has a value
     * @param  Override|null  $override  the user's typed value, if any
     */
    public function resolve(string $facet, string $column, array $contributions, ?Override $override = null): mixed
    {
        // 1. The user wins, including when they cleared the field.
        if ($override !== null) {
            return $override->value;
        }

        $contributions = array_values(array_filter(
            $contributions,
            fn (Contribution $c) => $c->value !== null && $c->value !== '',
        ));

        if ($contributions === []) {
            return null;
        }

        return match (self::ruleFor($facet, $column)) {
            ColumnRule::Union => $this->union($contributions),
            ColumnRule::Longest => $this->longest($contributions),
            ColumnRule::Recency => $this->recent($contributions),
            ColumnRule::SourcePriority => $this->byPriority($contributions),
        };
    }

    /** @param list<Contribution> $contributions */
    private function byPriority(array $contributions): mixed
    {
        usort($contributions, fn (Contribution $a, Contribution $b) => [$b->sourcePriority, $b->changedAt ?? 0] <=> [$a->sourcePriority, $a->changedAt ?? 0]);

        return $contributions[0]->value;
    }

    /** @param list<Contribution> $contributions */
    private function longest(array $contributions): mixed
    {
        usort($contributions, function (Contribution $a, Contribution $b) {
            $lengthA = is_string($a->value) ? mb_strlen($a->value) : 0;
            $lengthB = is_string($b->value) ? mb_strlen($b->value) : 0;

            // Length first, then priority — so two equally complete
            // descriptions resolve predictably rather than by array order.
            return [$lengthB, $b->sourcePriority] <=> [$lengthA, $a->sourcePriority];
        });

        return $contributions[0]->value;
    }

    /**
     * Most recently CHANGED wins, but only past the dwell threshold — and
     * "changed" means the content changed, not that we fetched it again.
     *
     * @param  list<Contribution>  $contributions
     */
    private function recent(array $contributions): mixed
    {
        usort($contributions, fn (Contribution $a, Contribution $b) => [$b->sourcePriority] <=> [$a->sourcePriority]);
        $incumbent = $contributions[0];

        foreach ($contributions as $challenger) {
            if ($challenger === $incumbent || $challenger->changedAt === null || $incumbent->changedAt === null) {
                continue;
            }
            $hoursNewer = ($challenger->changedAt - $incumbent->changedAt) / 3600;
            if ($hoursNewer > self::RECENCY_DWELL_HOURS) {
                $incumbent = $challenger;
            }
        }

        return $incumbent->value;
    }

    /** @param list<Contribution> $contributions */
    private function union(array $contributions): array
    {
        $out = [];
        foreach ($contributions as $contribution) {
            foreach ((array) $contribution->value as $element) {
                if (! in_array($element, $out, true)) {
                    $out[] = $element;
                }
            }
        }

        return $out;
    }
}
