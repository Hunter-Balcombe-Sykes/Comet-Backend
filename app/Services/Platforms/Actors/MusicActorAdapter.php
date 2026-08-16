<?php

namespace App\Services\Platforms\Actors;

/**
 * One music platform's view of "run an actor, get tracks back".
 *
 * The driver owns budget, token and transport; an adapter owns ONLY the
 * vendor's input shape and the field names its dataset happens to use. That
 * split matters here more than it does for Instagram: the actor field names
 * were pinned by a live probe rather than by documentation (convergence-log
 * F29 records why), so they are the part most likely to move. When an actor
 * build changes its shape, exactly one class changes.
 */
interface MusicActorAdapter
{
    /**
     * The actor's own input payload for this identifier. Implementations MUST
     * anchor on the identifier (an artist/profile URL) rather than a name
     * search — a search can resolve to a different artist sharing the name.
     *
     * @return array<string, mixed>
     */
    public function input(string $identifier, int $maxTracks): array;

    /**
     * @param  list<mixed>  $dataset
     * @return list<array{external_id: string, title: string, url: string, artist: ?string, isrc: ?string, duration_seconds: ?int, published: ?string, artwork: ?string}>
     */
    public function tracks(array $dataset): array;
}
