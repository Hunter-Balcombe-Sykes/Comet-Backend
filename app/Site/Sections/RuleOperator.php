<?php

namespace App\Site\Sections;

/**
 * The complete operator set for section rules — NINE (plan §7 shipped
 * seven; `latest_per_auto_source` joined 2026-08-05 for the pools lane,
 * `upcoming_occurrence` 2026-08-11 for the events pool).
 * A bounded DSL is what lets the same rule be validated, rendered to the
 * user as an English sentence, and explained in a trace. An open query
 * language could do none of those.
 */
enum RuleOperator: string
{
    case KindIs = 'kind_is';           // kind ∈ [...]
    case HasFacet = 'has_facet';       // item carries this facet
    case FromSource = 'from_source';   // contributed by these sources
    case InCollection = 'in_collection';
    case TaggedWith = 'tagged_with';
    case PublishedWithin = 'published_within'; // last N days

    // The auto half of a pool section (platforms-as-sources, 2026-08-05):
    // matches an item iff it is the NEWEST non-removed item of its
    // connection-source (among the given kinds; the item's own kind when
    // none given) AND that connection's display_settings.auto_sync_latest
    // is not off (sparse — absent means ON). Read-time by design: C4 says
    // no engine may write pins, and a rule needs no engine — a newer item
    // simply wins the next resolve, which IS the rolling behaviour.
    case LatestPerAutoSource = 'latest_per_auto_source';

    // The auto half of the MEDIA pool (overnight 2026-08-18, owner ruling R5:
    // media is opt-in — pins plus each source's N newest while that source's
    // sparse toggles are on). Same predicate as LatestPerAutoSource with a
    // rank window instead of "the one newest": an item matches iff fewer
    // than N items from the same connection-source (among the given kinds)
    // are newer. N = config('partna.pools.auto_latest_n'). Also honours the
    // google-business `photos` toggle, so switching photos off hides that
    // source's media without an exclude per item.
    case LatestNPerAutoSource = 'latest_n_per_auto_source';

    // The auto half of a DATED pool (events, 2026-08-11): the item occurs at
    // or after now, with a day of grace so something running today does not
    // vanish at its start time. An item with no f_occurrence row does NOT
    // match — "upcoming" asserts a date we do not have.
    case UpcomingOccurrence = 'upcoming_occurrence';
    case HasAction = 'has_action';     // carries this action intent

    /**
     * Rendered into the sentence the user actually reads.
     *
     * Exhaustive with NO default arm, and that is a deliberate choice rather
     * than an oversight. A missing arm is an UnhandledMatchError, and
     * SectionResource calls this on every serialise — so an unphrased case
     * would 500 a dashboard read. A runtime fallback cannot guard that here:
     * it is unreachable while the match is exhaustive, and PHPStan fails the
     * build on the dead arm (tried both a `default =>` and a `?? ` lookup,
     * 2026-08-11). The guard therefore lives in the test suite —
     * RuleOperatorTest's "renders a phrase for every operator" walks
     * cases() and throws on the first case added without an arm, which
     * catches the omission in CI instead of in production.
     */
    public function phrase(): string
    {
        return match ($this) {
            self::KindIs => 'is a',
            self::HasFacet => 'has',
            self::FromSource => 'comes from',
            self::InCollection => 'is in',
            self::TaggedWith => 'is tagged',
            self::PublishedWithin => 'was published within',
            self::LatestPerAutoSource => "is a platform's newest",
            self::LatestNPerAutoSource => "is among a platform's newest",
            self::HasAction => 'can be',
            self::UpcomingOccurrence => 'is upcoming',
        };
    }
}
