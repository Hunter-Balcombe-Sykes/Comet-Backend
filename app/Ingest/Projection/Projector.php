<?php

namespace App\Ingest\Projection;

/**
 * Turns one landed record into the typed shape the content layer stores.
 * Versioned: a projector's output for a given doc must be reproducible, so
 * `ingest:project --rebuild` can re-derive everything from the record log
 * without re-fetching a single byte.
 *
 * Projectors are PURE — no I/O, no database, no clock. That is what makes
 * Tier-P tests take vendor JSON in and assert a structure out, with nothing
 * else in the room.
 */
interface Projector
{
    /** Bump when output changes for unchanged input; triggers a rebuild. */
    public static function version(): int;

    /** The item kind this projector produces (plan §6's closed enum). */
    public static function kind(): string;

    /**
     * @return array<string, mixed>|null null = this record projects to nothing
     *                                   (a draft, a hidden item, an unsupported type)
     */
    public function project(RecordView $view): ?array;
}
