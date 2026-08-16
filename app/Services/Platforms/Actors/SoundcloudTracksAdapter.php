<?php

namespace App\Services\Platforms\Actors;

/**
 * automation-lab~soundcloud-scraper, in userUrl mode.
 *
 * The cleaner of the two music actors: it takes the profile permalink the
 * connection already stores, so it walks that artist's uploads and nobody
 * else's, and it returns `isrc` — which makes SoundCloud the first producer
 * the Isrc joining key has ever had (convergence-log F10).
 */
class SoundcloudTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        return [
            'mode' => 'userUrl',
            'startUrls' => [['url' => $identifier]],
            'maxResults' => $maxTracks,
            'includeUserDetails' => true,
        ];
    }

    public function tracks(array $dataset): array
    {
        $out = [];

        foreach ($dataset as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $isrc = $row['isrc'] ?? null;

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                // includeUserDetails nests the uploader; that name is what
                // f_authored.creator carries, and therefore what the
                // title|artist cross-platform key is built from.
                'artist' => $this->username($row['user'] ?? null),
                'isrc' => is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null,
                'duration_seconds' => $this->seconds($row['duration'] ?? null),
                'published' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
                'artwork' => is_string($row['artworkUrl'] ?? null) ? $row['artworkUrl'] : null,
            ];
        }

        return $out;
    }

    private function username(mixed $user): ?string
    {
        if (is_string($user)) {
            return trim($user) !== '' ? trim($user) : null;
        }

        if (is_array($user)) {
            foreach (['username', 'full_name', 'permalink'] as $key) {
                $name = $user[$key] ?? null;
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return null;
    }

    /** SoundCloud reports duration in milliseconds, as Spotify does. */
    private function seconds(mixed $ms): ?int
    {
        return is_numeric($ms) && (int) $ms > 0 ? (int) round(((int) $ms) / 1000) : null;
    }
}
