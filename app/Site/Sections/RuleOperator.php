<?php

namespace App\Site\Sections;

/**
 * The complete operator set for section rules — SEVEN, deliberately (plan
 * §7). A bounded DSL is what lets the same rule be validated, rendered to the
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
            self::HasAction => 'can be',
        };
    }
}
