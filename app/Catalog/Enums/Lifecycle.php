<?php

namespace App\Catalog\Enums;

/**
 * A brand or surface's place in its lifecycle:
 *
 *   - Active  — normal, appears in pickers and connect UI.
 *   - Hidden  — still routable (existing connections keep working) but
 *               dropped from pickers/connect UI.
 *   - Sunset  — superseded by a successor brand (see Brand::$successorKey).
 *   - Retired — tombstone; kept only so historical detector/identifier data
 *               still resolves to a known key.
 */
enum Lifecycle: string
{
    case Active = 'active';
    case Hidden = 'hidden';
    case Sunset = 'sunset';
    case Retired = 'retired';
}
