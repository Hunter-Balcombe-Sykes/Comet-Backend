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

// Link-only Reddit connect — same shape as Facebook/TikTok. Accepts user
// profiles (u/name, reddit.com/user/name) AND subreddits (r/name) since
// creators commonly run a community rather than post from a profile. No
// scraping, no refresh.
class RedditController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'reddit';
    }

    // POST /api/platforms/reddit/connect — store the profile/community link.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $selection = $this->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error('Enter your Reddit username or community (u/yourname or r/yourcommunity).', 422);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/reddit/selection — the saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/reddit — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Accept "u/name", "user/name", "r/community", a reddit.com URL carrying
    // either form, an old.reddit.com URL, or a bare username → {username, url}.
    /**
     * @return array{username:string, url:string}|null
     */
    private function normalize(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        // URL forms (any reddit subdomain) and bare "u/…" / "r/…" prefixes.
        if (preg_match('~(?:reddit\.com/|^)(u|user|r)/([^/?#]+)~i', $s, $m)) {
            $kind = strtolower($m[1]) === 'r' ? 'r' : 'user';
            $name = $m[2];
        } elseif (str_contains($s, 'reddit.com')) {
            // A reddit.com URL without a profile/community path.
            return null;
        } else {
            $kind = 'user';
            $name = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_\-]{2,21}$~', $name)) {
            return null;
        }

        return [
            'username' => $name,
            'url' => "https://www.reddit.com/{$kind}/{$name}/",
        ];
    }
}
