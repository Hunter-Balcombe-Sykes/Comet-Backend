<?php

namespace App\Content\Values;

/**
 * How ONE column picks a winner when sources disagree (plan §6). Tiny and
 * declared per column rather than inferred, because the right answer differs
 * by field: the longest description is usually the best one, but the longest
 * TITLE is usually the one with junk appended.
 */
enum ColumnRule: string
{
    /** The most recently CHANGED value wins (not most recently fetched). */
    case Recency = 'recency';

    /** The highest-priority source wins. Manual always outranks. */
    case SourcePriority = 'source_priority';

    /** The longest value wins — right for bodies, wrong for titles. */
    case Longest = 'longest';

    /** Every source's values combine (tags, collaborators). */
    case Union = 'union';
}
