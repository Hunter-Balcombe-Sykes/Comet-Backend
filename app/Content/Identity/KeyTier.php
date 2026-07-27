<?php

namespace App\Content\Identity;

/**
 * How much weight a key class carries when deciding whether two source items
 * are the same thing.
 */
enum KeyTier: string
{
    /** Alone sufficient to merge: a shared value IS identity (ISRC, GTIN). */
    case Joining = 'joining';

    /** Merges only with corroboration and only when unambiguous. */
    case Corroborating = 'corroborating';

    /** Never merges on its own; feeds the candidates queue for a human. */
    case Evidential = 'evidential';
}
