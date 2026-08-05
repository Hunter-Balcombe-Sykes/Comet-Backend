<?php

namespace App\Services\Content;

use App\Models\Core\Site\Section;
use App\Site\Documents\BuildState;
use App\Site\Documents\DocumentBuilder;
use App\Site\Sections\RuleOperator;
use App\Site\Sections\SectionCandidates;
use Illuminate\Support\Facades\DB;

/**
 * "Why is this thing on my page — and why isn't that one?" (plan §7).
 *
 * The trace answers per candidate, not in aggregate, because the 3am version
 * of the question is always about ONE item. It deliberately mirrors
 * {@see DocumentBuilder} rather than describing the rule
 * as designed: a diagnostic that disagrees with the live page is worse than no
 * diagnostic. Where the builder ignores an operator, this says so in the
 * `gaps` list instead of pretending the predicate was applied.
 *
 * Which operators actually execute is read from
 * {@see DocumentBuilder::EXECUTED_OPERATORS} rather than restated here. This
 * class used to keep its own list naming only kind_is and published_within,
 * and the builder grew the other five without it — so the trace reported
 * has_facet, from_source, in_collection, tagged_with and has_action as
 * "stored and validated but not yet applied" while they were, in fact,
 * filtering the live page. A trace that disagrees with the page sends someone
 * hunting a bug that isn't there, which is the exact failure the mirroring is
 * supposed to prevent.
 */
final class SectionTracer
{
    /** Mirrors DocumentBuilder::ruleCandidates()' own bound. */
    private const CANDIDATE_SCAN_LIMIT = 200;

    /**
     * Never restate this list. DocumentBuilder owns it; a private copy here is
     * exactly what drifted.
     *
     * @var list<string>
     */
    private const EXECUTED_OPERATORS = DocumentBuilder::EXECUTED_OPERATORS;

    /**
     * @return array<string, mixed>
     */
    public function trace(Section $section): array
    {
        $rule = $section->ruleObject();
        $predicates = $this->tracePredicates($section);
        $curation = $this->curationFor($section);
        $buildState = BuildState::read((string) $section->site_id);

        return [
            'section' => [
                'id' => (string) $section->id,
                'key' => $section->key,
                'label' => $section->label,
                'mode' => $section->mode,
                'orderBy' => $section->order_by,
                'limitN' => $section->limit_n === null ? null : (int) $section->limit_n,
                'minItems' => (int) $section->min_items,
                'onEmpty' => $section->on_empty,
            ],
            'rule' => [
                'sentence' => $rule->toSentence(),
                'predicates' => $predicates,
            ],
            // The builder reads this BEFORE building and commits under CAS, so
            // "stale" here is the honest answer to "is my page showing this yet?"
            'buildState' => [
                'contentRevision' => $buildState['content_revision'],
                'builtRevision' => $buildState['built_revision'],
                'stale' => $buildState['content_revision'] > $buildState['built_revision'],
            ],
            'candidates' => $this->traceCandidates($section, $curation),
            'gaps' => $this->gaps($predicates),
        ];
    }

    /**
     * @return array{pinned: list<string>, excluded: array<string, true>, sortKeys: array<string, float|null>}
     */
    private function curationFor(Section $section): array
    {
        $rows = DB::table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $pinned = $rows->where('state', 'pinned')->sortBy('sort_key');

        return [
            'pinned' => array_values($pinned->pluck('item_id')->all()),
            'excluded' => array_fill_keys($rows->where('state', 'excluded')->pluck('item_id')->all(), true),
            'sortKeys' => $pinned->pluck('sort_key', 'item_id')->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tracePredicates(Section $section): array
    {
        $out = [];

        foreach ($section->ruleObject()->predicates as $predicate) {
            $executed = in_array($predicate->operator->value, self::EXECUTED_OPERATORS, true);

            $out[] = [
                'op' => $predicate->operator->value,
                'values' => $predicate->values,
                'negated' => $predicate->negated,
                'status' => $executed ? 'applied' : 'ignored',
                'reason' => $this->predicateReason($predicate->operator, $executed),
            ];
        }

        return $out;
    }

    private function predicateReason(RuleOperator $operator, bool $executed): string
    {
        // Reachable only for an operator declared in RuleOperator but absent
        // from DocumentBuilder::EXECUTED_OPERATORS. Every operator is executed
        // today, so nothing hits this — it is kept deliberately generic so
        // that the next one added is described correctly by default rather
        // than by a stale sentence about whichever joins were missing in 2026.
        if (! $executed) {
            return 'Saved and shown in the rule, but not yet narrowing the list: the document builder '
                .'does not apply this condition when it builds the page.';
        }

        return match ($operator) {
            // Named rather than hidden: the builder prefers the item's own
            // published facet and falls back to FIRST-seen for items nothing
            // ever dated, so a long-standing item that was only recently
            // ingested still counts as new. (This used to say "last seen",
            // conflating the filter with the recency ORDERING, which is the
            // column last_seen_at actually drives.)
            RuleOperator::PublishedWithin => 'Applied against the item\'s published date, falling back to when it '
                .'was first seen for items no source ever dated.',
            default => 'Applied.',
        };
    }

    /**
     * @param  array{pinned: list<string>, excluded: array<string, true>, sortKeys: array<string, float|null>}  $curation
     * @return list<array<string, mixed>>
     */
    private function traceCandidates(Section $section, array $curation): array
    {
        // Hand-picked sections are exactly their pins: the rule is never
        // consulted, so enumerating rule candidates here would invent a
        // shortlist the live page does not have.
        $ruleCandidateIds = $section->mode === 'hand_picked'
            ? []
            : $this->ruleCandidateIds($section, $curation['pinned']);

        $ordered = array_merge($curation['pinned'], $ruleCandidateIds);
        $excludedOnly = array_diff(array_keys($curation['excluded']), $ordered);

        $items = $this->itemsById(array_merge($ordered, $excludedOnly), (string) $section->site_id);

        $out = [];
        $admitted = 0;
        $limit = $section->limit_n === null ? null : (int) $section->limit_n;

        foreach ($ordered as $position => $itemId) {
            $item = $items[$itemId] ?? null;
            $isPinned = in_array($itemId, $curation['pinned'], true);

            [$verdict, $reason] = $this->verdictFor(
                item: $item,
                isPinned: $isPinned,
                isExcluded: isset($curation['excluded'][$itemId]),
                overLimit: $limit !== null && $admitted >= $limit,
                position: $position,
            );

            if ($verdict === 'included' || $verdict === 'pinned') {
                $admitted++;
            }

            $out[] = $this->candidateRow($itemId, $item, $verdict, $reason, $curation['sortKeys'][$itemId] ?? null);
        }

        // Items the user hid that the rule would not have produced anyway.
        // Listing them is the point: a hide that did nothing still looks like
        // a hide in the UI, and the user deserves to be told which it was.
        foreach ($excludedOnly as $itemId) {
            $out[] = $this->candidateRow(
                $itemId,
                $items[$itemId] ?? null,
                'excluded',
                'Hidden by you. The rule would not have included it anyway.',
                null,
            );
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function verdictFor(?object $item, bool $isPinned, bool $isExcluded, bool $overLimit, int $position): array
    {
        if ($item === null) {
            return ['missing', 'This item no longer exists in your library.'];
        }

        if ($item->removed_at !== null) {
            return ['removed', 'You deleted this item, so nothing can show it.'];
        }

        // Excludes beat pins: the last thing the user said wins, and hiding
        // something you previously pinned is a normal way to change your mind.
        if ($isExcluded) {
            return ['excluded', 'Hidden by you in this section.'];
        }

        if ($overLimit) {
            return ['over_limit', 'Matched, but the section is already full.'];
        }

        if ($isPinned) {
            return ['pinned', 'Pinned by you at position '.($position + 1).'.'];
        }

        return ['included', 'Matched the rule.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateRow(string $itemId, ?object $item, string $verdict, string $reason, float|int|string|null $sortKey): array
    {
        return [
            'itemId' => $itemId,
            'kind' => $item->kind ?? null,
            'headline' => $item->headline_cache ?? null,
            'verdict' => $verdict,
            'reason' => $reason,
            'sortKey' => $sortKey === null ? null : (float) $sortKey,
        ];
    }

    /**
     * Delegates to the ONE rule executor. This method used to keep a "mirror"
     * of DocumentBuilder::ruleCandidates() that executed two of the seven
     * operators — and filtered published_within on last_seen_at while the
     * builder used the published facet — so the trace enumerated candidates
     * the live page never had. Exactly the drift this class's header warns
     * about; fixed 2026-08-05 by extracting {@see SectionCandidates} and
     * pointing builder, tracer and pools at the same query.
     *
     * @param  list<string>  $alreadyPinned
     * @return list<string>
     */
    private function ruleCandidateIds(Section $section, array $alreadyPinned): array
    {
        return app(SectionCandidates::class)
            ->ruleCandidates($section, $alreadyPinned, self::CANDIDATE_SCAN_LIMIT);
    }

    /**
     * Owner-scoped exactly as {@see DocumentBuilder::itemsById()} is, and for
     * the same reason: a pin naming a foreign item must not resolve. This class
     * mirrors the builder on purpose — a trace that showed an item the live
     * page refuses to render would send someone hunting the wrong bug, and a
     * trace is also the one place a foreign headline would be read by a human.
     *
     * @param  list<string>  $ids
     * @return array<string, object>
     */
    private function itemsById(array $ids, string $siteId): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('content.items')
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereIn('user_id', fn ($q) => $q
                ->from('site.sites')->select('user_id')->where('id', $siteId))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * What this trace cannot tell you yet, stated rather than implied by
     * absence.
     *
     * @param  list<array<string, mixed>>  $predicates
     * @return list<array{code: string, detail: string}>
     */
    private function gaps(array $predicates): array
    {
        $gaps = [];

        $ignored = array_values(array_unique(array_map(
            fn (array $p): string => (string) $p['op'],
            array_filter($predicates, fn (array $p): bool => $p['status'] === 'ignored'),
        )));

        if ($ignored !== []) {
            $gaps[] = [
                'code' => 'unexecuted_operators',
                'detail' => 'These conditions are stored and validated but not yet applied when the page is '
                    .'built, so the list below is wider than the rule reads: '.implode(', ', $ignored).'.',
            ];
        }

        return $gaps;
    }
}
