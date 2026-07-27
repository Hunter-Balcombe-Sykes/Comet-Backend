<?php

namespace App\Ingest\Manifest;

/**
 * What KIND of thing a stream is — the single declaration that decides
 * cadence, whether absence may ever mean deletion, and how the UI treats the
 * stream's items (plan §4).
 */
enum SourceProfile: string
{
    /** A complete list we can mirror; absence is meaningful. */
    case Mirror = 'mirror';

    /** A sample of a larger set (reviews): never complete, NEVER deletes. */
    case Sample = 'sample';

    /** A catalogue we page through; absence meaningful within a window. */
    case Catalogue = 'catalogue';

    /** A reverse-chronological feed; only a prefix is authoritative. */
    case Feed = 'feed';

    /** Dated occurrences; the past falling off is not deletion. */
    case Calendar = 'calendar';

    /** The account's own facts (name, address, avatar). */
    case Identity = 'identity';

    /**
     * A Sample can never delete and can never dominate: what we hold is a
     * snapshot of what the vendor chose to show us, not the whole set.
     */
    public function mayDelete(): bool
    {
        return $this !== self::Sample;
    }

    /** [min, max] seconds between runs. */
    public function cadenceBand(): array
    {
        return match ($this) {
            self::Identity => [86400, 604800],
            self::Mirror, self::Catalogue => [21600, 259200],
            self::Feed => [10800, 86400],
            self::Calendar => [21600, 172800],
            self::Sample => [43200, 604800],
        };
    }

    /** Whether the dashboard lets a user pin/reorder this stream's items. */
    public function isPinnable(): bool
    {
        return $this !== self::Sample && $this !== self::Identity;
    }

    /** Whether a user may edit values this stream produced. */
    public function isEditable(): bool
    {
        // Reviews are third-party statements: showing or hiding them is
        // curation, editing them would be forgery.
        return $this !== self::Sample;
    }

    /** Default for showing items the source no longer reports. */
    public function staleDisplayDefault(): string
    {
        return match ($this) {
            self::Catalogue, self::Feed => 'hide',
            default => 'show',
        };
    }
}
