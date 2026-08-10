<?php

namespace App\Services\Profile;

/**
 * Splits a scraped platform "full name" into first/last parts.
 *
 * Instagram's fullName is a free-text vanity field, not a name column — it
 * routinely carries a trailing tagline ("SIMON DOYLE | Barber & Educator").
 * displayName keeps the raw string verbatim because that is what renders;
 * only the name PARTS are derived, and only for FreshaStaffMatcher's benefit.
 */
final class PersonNameParser
{
    /** Tagline separators seen in the wild, in the order they are stripped. */
    private const SEPARATORS = ['|', '–', '—', '•'];

    /** @return array{displayName: string, firstName: string, lastName: ?string} */
    public static function parse(string $fullName): array
    {
        $namePart = $fullName;
        foreach (self::SEPARATORS as $separator) {
            $namePart = explode($separator, $namePart, 2)[0];
        }

        // array_values: array_filter preserves keys, so a double space would
        // otherwise leave $tokens[0] unset.
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($namePart)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        return [
            'displayName' => $fullName,
            'firstName' => $tokens[0] ?? '',
            'lastName' => count($tokens) > 1 ? (string) end($tokens) : null,
        ];
    }
}
