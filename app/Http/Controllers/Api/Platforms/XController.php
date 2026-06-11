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

// Link-only X (Twitter) connect — same shape as Facebook/TikTok. The user
// gives a handle or profile URL and we store the canonical x.com link; no
// scraping, no refresh.
class XController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    // Non-profile first path segments on x.com — pasting one of these means
    // the input wasn't a profile URL.
    private const RESERVED = [
        'i', 'home', 'explore', 'search', 'hashtag', 'intent', 'share',
        'settings', 'notifications', 'messages', 'compose', 'login', 'signup',
    ];

    protected function platform(): string
    {
        return 'x';
    }

    // POST /api/platforms/x/connect — store the profile link for the user.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $selection = $this->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error('Enter your X handle or profile URL (x.com/yourname).', 422);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/x/selection — the authenticated user's saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/x — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Accept a bare handle, @handle, or an x.com / twitter.com profile URL
    // (any scheme or none, extra path/query tolerated) → {username, url}.
    /**
     * @return array{username:string, url:string}|null
     */
    private function normalize(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:x|twitter)\.com/([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
            if (in_array(strtolower($candidate), self::RESERVED, true)) {
                return null;
            }
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_]{1,15}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://x.com/'.$candidate];
    }
}
