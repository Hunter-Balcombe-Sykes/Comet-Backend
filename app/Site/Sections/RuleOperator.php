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

    // The auto half of a DATED pool (events, 2026-08-11): the item occurs at
    // or after now, with a day of grace so something running today does not
    // vanish at its start time. An item with no f_occurrence row does NOT
    // match — "upcoming" asserts a date we do not have.
    case UpcomingOccurrence = 'upcoming_occurrence';
    case HasAction = 'has_action';     // carries this action intent

    /** Rendered into the sentence the user actually reads. */
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
            self::HasAction => 'can be',
            self::UpcomingOccurrence => 'is upcoming',
            // A missing arm used to be an UnhandledMatchError on a path that
            // renders a sentence — a broken phrase beats a 500.
            default => str_replace('_', ' ', $this->value),
        };
    }
}
