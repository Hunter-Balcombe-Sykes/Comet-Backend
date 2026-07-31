<?php

namespace App\Services\Platforms;

// The single source of truth for menu dish/category name matching (#INH-6).
//
// This normalizer is load-bearing, not cosmetic: it builds the key that decides
// whether a scraped dish IS the dish the owner already suppressed. It used to be
// copy-pasted into MenuContentController, MenuFetchJob and MenuMerger (as norm())
// with comments asking editors to keep the three "IDENTICAL" — so a drift in any
// one of them made a suppressed dish stop matching at rebuild time and silently
// reappear on the public menu. Pinned by
// tests/Unit/Platforms/MenuItemNameNormalizationTest.php.
//
// Deliberately its own trait rather than a method on NormalizesMenuData: that
// trait's cleanString() would silently shadow the classes' own same-named
// private methods (PHP resolves the class method over the trait's with no error
// and no signature check), and its unused members would trip PHPStan's
// unused-private-method rule on the consumers here.
trait NormalizesMenuItemNames
{
    /**
     * lowercase, non-alphanumerics → single spaces, trimmed.
     *
     * Note accents are STRIPPED, not folded — "Café Latte" normalises to
     * "caf latte", so it is a different dish from "Cafe Latte". That is
     * existing, tested behaviour, not an oversight.
     */
    private function normalizeName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

        return trim((string) preg_replace('~\s+~', ' ', $s));
    }
}
