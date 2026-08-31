<?php

namespace App\Site\Pools;

/**
 * Every `content.f_review` row one review item carries, and the two different
 * questions the reviews lane is allowed to ask of them.
 *
 * THE INCIDENT (2026-09-01, fourth pass). Admission and publication were
 * reading DIFFERENT ROWS OF THE SAME REVIEW. `content.f_review` is keyed
 * (item_id, source_id), so a review carried by two vendors — a venue's Google
 * listing and its Fresha page, deduped upstream onto one content.items row —
 * has TWO rows. `reviewsOutsidePersonScope()` read them `orderBy(updated_at)`
 * and admitted the item on the first row whose prose named the owner;
 * `itemPayloads()` read the same rows `orderBy(updated_at)->keyBy(item_id)`,
 * which keeps the LAST. The row that justified admission was deterministically
 * not the row that rendered: "Raff was wonderful" on the older Google copy let
 * the item through, and the newer Fresha copy — "Great service today,
 * thanks!", naming nobody — is what the visitor saw. Three waves of guards all
 * reasoned about a row the page does not show, which is why each one produced
 * another blocker instead of a fix.
 *
 * So the authority question is asked ONCE, here, and both callers read the
 * answer off the same object:
 *
 *   published()          the ONE row that renders. The payload's `review`
 *                        block builds from it and the prose admission below
 *                        tests it, so no future guard can be written about a
 *                        row the visitor never sees.
 *   staffNames()         the vendor's structured attribution across EVERY row.
 *                        Set-level on purpose, and in the conservative
 *                        direction: two vendors disagreeing about whose review
 *                        this is is an uncertainty, not a tie for updated_at
 *                        to break.
 *
 * The asymmetry is the design, not an oversight. Structured attribution is a
 * claim about the REVIEW (one review, however many vendors retell it), so it
 * is read over the set. Prose is a claim about the WORDS ON THE CARD, and only
 * one vendor's wording is ever on the card, so it is read over the published
 * row alone. Both directions fail closed.
 *
 * Rows arrive in publication order — see ReviewFacets::forItems(), which owns
 * the total order — and this class never re-sorts them.
 */
final class ReviewFacetSet
{
    /** @param list<object> $rows in publication order; the LAST is the row that renders */
    private function __construct(private readonly array $rows) {}

    /**
     * @param  list<object>  $rows  ALREADY ordered by ReviewFacets::forItems()
     */
    public static function of(array $rows): self
    {
        return new self($rows);
    }

    /**
     * The row a visitor actually reads: author, rating, prose, date. Null when
     * the item has no facet row at all — an item that cannot be rendered as a
     * review, which the payload publishes as `review: null` and the scope
     * treats as an uncertainty.
     */
    public function published(): ?object
    {
        return $this->rows === [] ? null : $this->rows[array_key_last($this->rows)];
    }

    /**
     * The vendor's structured attribution from every row that carries one.
     *
     * EVERY row, not the published one: ollies' hair reviews arrive from the
     * Google listing (no staff_name) and from Fresha (staff_name "Ciel"), and
     * an attribution naming a colleague has to veto from whichever row happens
     * to hold it. The caller must consider all of them — one matching name
     * does not clear a set that also names somebody else.
     *
     * @return list<string>
     */
    public function staffNames(): array
    {
        $names = [];
        foreach ($this->rows as $row) {
            $staffName = trim((string) ($row->staff_name ?? ''));
            if ($staffName !== '') {
                $names[] = $staffName;
            }
        }

        return $names;
    }

    /**
     * Does the prose that will be ON THE PAGE name this person?
     *
     * The published row only. A text mention is evidence about the sentence a
     * visitor reads, and reading it off a row that will not be rendered is
     * precisely the divergence this class exists to end: the Google copy said
     * "Raff was wonderful" and the Fresha copy that published said nothing of
     * the sort. Where the two vendors' wording differs, the quieter one wins
     * and the review stays with the venue.
     *
     * @param  array{full: list<string>, first: list<string>}|null  $names
     */
    public function publishedTextNames(?array $names): bool
    {
        $row = $this->published();

        return $row !== null && PersonNameMatch::matchesText($row->text ?? null, $names);
    }
}
