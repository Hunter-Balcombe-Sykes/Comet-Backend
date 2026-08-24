<?php

namespace App\Services\Content;

use App\Content\Values\ValueResolver;

/**
 * Which (facet, column) pairs a user may override by hand (plan §6).
 *
 * A manual override is honoured absolutely by {@see ValueResolver}
 * — it outranks every source, forever, until the row is deleted. That makes an
 * unvalidated facet/column pair a durable lie: a row nothing reads, sitting in
 * the table looking authoritative. So the write path checks against this list
 * rather than accepting whatever the client sends.
 *
 * The list is the SINGLETON facets only. Collections (item_media, offers,
 * item_variants, item_tags, f_action) resolve by set-union and have
 * no single value to override — editing those is add/remove of rows, not a
 * per-column lock, and belongs to the collection endpoints rather than here.
 *
 * Identity columns are deliberately absent: a user retyping an ISRC would not
 * correct the catalogue, it would poison the resolver's joining keys.
 */
final class FacetRegistry
{
    /**
     * facet => column => wire type (for the dashboard's field renderer).
     *
     * @var array<string, array<string, string>>
     */
    private const FACETS = [
        'f_text' => [
            'headline' => 'string',
            'body' => 'text',
            'summary' => 'text',
        ],
        'f_link' => [
            'url' => 'url',
            'canonical_url' => 'url',
        ],
        'f_duration' => [
            'seconds' => 'integer',
        ],
        'f_published' => [
            'published_from' => 'datetime',
            'published_to' => 'datetime',
        ],
        'f_occurrence' => [
            'starts_at_local' => 'datetime',
            'ends_at_local' => 'datetime',
            'timezone' => 'string',
            'is_all_day' => 'boolean',
        ],
        'f_embed' => [
            'variant' => 'string',
        ],
        'f_authored' => [
            'creator' => 'string',
            'creator_url' => 'url',
            'collaborators' => 'list',
        ],
        'f_catalog' => [
            'release_type' => 'string',
            'track_number' => 'integer',
            'disc_number' => 'integer',
            // The release a track belongs to (listen restructure 2026-08-18).
            'collection_title' => 'string',
        ],
        'f_place' => [
            'venue_name' => 'string',
            'address' => 'string',
            'locality' => 'string',
            'region' => 'string',
            'country_code' => 'string',
        ],
        'f_channel' => [
            'handle' => 'string',
        ],
        'f_file' => [
            'file_url' => 'url',
        ],
    ];

    /** @return list<string> */
    public static function facets(): array
    {
        return array_keys(self::FACETS);
    }

    public static function allows(string $facet, string $column): bool
    {
        return isset(self::FACETS[$facet][$column]);
    }

    public static function typeOf(string $facet, string $column): ?string
    {
        return self::FACETS[$facet][$column] ?? null;
    }

    /**
     * Every overridable column, flattened — the shape the kinds endpoint and
     * the field renderer both consume.
     *
     * @param  list<string>  $facets
     * @return list<array{facet: string, column: string, type: string}>
     */
    public static function columnsForFacets(array $facets): array
    {
        $out = [];
        foreach ($facets as $facet) {
            foreach (self::FACETS[$facet] ?? [] as $column => $type) {
                $out[] = ['facet' => $facet, 'column' => $column, 'type' => $type];
            }
        }

        return $out;
    }
}
