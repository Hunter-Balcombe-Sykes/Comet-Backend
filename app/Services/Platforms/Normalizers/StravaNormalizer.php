<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Strava link-only normalizer. Accepts a strava.com/clubs/<slug> URL, or a
// bare club slug/numeric id → {username, url}; null otherwise.
//
// Carried over from SourceProvisioner::stravaClubUrl(), deleted when Phase 1
// de-sourced Strava. Clubs are the only Strava resource this platform ever
// represented, so an athlete URL is deliberately refused rather than silently
// rewritten into a club link that would 404.
class StravaNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~strava\.com/clubs/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'strava.com')) {
            // A strava.com URL that is not a club — an athlete or activity
            // link. Refused rather than coerced.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_-]{2,60}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://www.strava.com/clubs/'.$candidate];
    }
}
