<?php

namespace App\Catalog\Enums;

// The shape of the identifier a Surface's connection stores — read by the
// generic connect-input normalizer to pick a parse strategy per surface.
enum IdentifierKind: string
{
    case Handle = 'handle';
    case NumericId = 'numeric_id';
    case Slug = 'slug';
    case Url = 'url';
    case Domain = 'domain';
    case PlaceId = 'place_id';
    case Composite = 'composite';
}
