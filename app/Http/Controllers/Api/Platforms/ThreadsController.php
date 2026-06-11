<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectSocialLinkRequest;
use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Services\Platforms\PlatformInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Link-only Threads connect — same shape as Facebook/TikTok. Threads handles
// mirror Instagram usernames; both threads.net and threads.com URLs are
// accepted. No scraping, no refresh.
class ThreadsController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'threads';
    }

    // POST /api/platforms/threads/connect — store the profile link.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $selection = $this->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error('Enter your Threads handle or profile URL (threads.net/@yourname).', 422);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/threads/selection — the saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/threads — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Accept a bare handle, @handle, or a threads.net / threads.com profile
    // URL (any scheme or none, trailing junk tolerated) → {username, url}.
    /**
     * @return array{username:string, url:string}|null
     */
    private function normalize(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~threads\.(?:net|com)/@?([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9._]{1,30}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://www.threads.net/@'.$candidate];
    }
}
