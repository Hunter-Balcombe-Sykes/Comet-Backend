<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

class ConnectSpotifyRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Any open.spotify.com entity link (artist/album/playlist/track/show/episode/user).
            'url' => ['required', 'string', 'max:500', 'regex:~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(?:artist|album|playlist|track|show|episode|user)/[A-Za-z0-9]+~i'],
        ];
    }
}
