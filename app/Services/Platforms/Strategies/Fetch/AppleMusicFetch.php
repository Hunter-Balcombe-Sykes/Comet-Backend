<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Apple Music artist's latest album by stored input. Preserves input +
// curated highlights; refreshes the "most recent" tile. Mirrors
// PlatformRefresher::appleMusicPayload EXACTLY.
final readonly class AppleMusicFetch implements FetchStrategy
{
    public function __construct(private AppleSearch $apple) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $input = $payload['input'] ?? null;
        if (! $input) {
            throw new FetchShapeException('missing_key: input');
        }

        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            throw new FetchUnavailableException('apple_music_no_albums');
        }
        $latest = $albums[0];

        $next = [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];

        // #76: (re)capture the artist genre on refresh so connections made before
        // genre capture existed pick it up on their next daily refresh, and a
        // changed classification stays current. Best-effort: retain the prior
        // value on any miss/throw. Only SET the key when we have a non-empty
        // genre — never introduce a `genre => null` (that would read as a payload
        // change and force an otherwise-pointless cache purge on legacy rows).
        try {
            $fresh = $this->apple->fetchGenre($input);
            if (is_string($fresh) && trim($fresh) !== '') {
                $next['genre'] = strtolower(trim($fresh));
            }
        } catch (\Throwable) {
            // ignore — the spread already preserved any existing $payload['genre']
        }

        return $next;
    }
}
