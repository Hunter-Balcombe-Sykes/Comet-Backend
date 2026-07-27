<?php

namespace App\Catalog\Enums;

// Where a Detector's identifierCapture regex reads the surface identifier
// from. None (the default) means the detector doesn't capture an identifier
// at all.
enum IdentifierSource: string
{
    case Path = 'path';
    case Query = 'query';
    case Subdomain = 'subdomain';
    case Fingerprint = 'fingerprint';
    case None = 'none';
}
