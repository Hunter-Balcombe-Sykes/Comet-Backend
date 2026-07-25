<?php

if (! function_exists('trim_or_null')) {
    /**
     * Trim a value and collapse it to null unless it's a non-empty string.
     * SLOP-1: dedupes the byte-identical trimToNull()/trimOrNull() private
     * methods previously duplicated in SitepageDataResolverService and
     * UserWorkplaceController.
     */
    function trim_or_null(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
