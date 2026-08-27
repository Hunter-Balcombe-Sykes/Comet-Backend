<?php

namespace App\Services\Platforms;

/**
 * T24/issue 17 (2026-08-28): the probe quality gate. playlunch's Links page
 * stored ticket-marketplace SEARCH-RESULT pages as link cards — real,
 * well-formed og:titles ("Tickets for … | Search results", German/Dutch/
 * French variants, raw "**PLAYLUNCH**" markdown) that LinkCardScraper had no
 * reason to doubt. This class is the doubt: a snapshot that looks like a
 * search/listing page, a noindex page, or markdown junk is DOWNGRADED to the
 * minimal host card — never dropped (zero-loss: the link stays a card, it
 * just stops wearing another site's search UI as its identity).
 */
final class LinkSnapshotQuality
{
    /** Path segments that mean "this is a search/results page", not a thing. */
    private const SEARCH_PATH = '~(^|/)(search|results?|suche|zoeken|recherche|buscar|busqueda|cerca|ricerca|find)(/|$)~i';

    /** Query keys that carry a search term. */
    private const SEARCH_QUERY_KEYS = ['q', 'query', 's', 'search', 'keyword', 'keywords', 'term', 'k'];

    /** Title shapes only a search/listing page produces. */
    private const JUNK_TITLE = [
        '~\bsearch results?\b~iu',
        '~\bresults? for\b~iu',
        '~\|\s*search\b~iu',
        '~\b\d+\s+(results?|treffer|resultaten|résultats|risultati|resultados)\b~iu',
        '~\*\*~u', // raw markdown reached a title — never legitimate page identity
    ];

    public static function acceptable(string $finalUrl, ?string $name, ?string $robotsMeta): bool
    {
        $path = (string) parse_url($finalUrl, PHP_URL_PATH);
        if (preg_match(self::SEARCH_PATH, $path) === 1) {
            return false;
        }

        parse_str((string) parse_url($finalUrl, PHP_URL_QUERY), $query);
        foreach (self::SEARCH_QUERY_KEYS as $key) {
            if (isset($query[$key]) && is_string($query[$key]) && trim($query[$key]) !== '') {
                return false;
            }
        }

        if (is_string($robotsMeta) && stripos($robotsMeta, 'noindex') !== false) {
            return false;
        }

        foreach (self::JUNK_TITLE as $pattern) {
            if (is_string($name) && preg_match($pattern, $name) === 1) {
                return false;
            }
        }

        return true;
    }
}
