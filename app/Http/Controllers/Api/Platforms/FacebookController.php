<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectFacebookRequest;
use App\Http\Resources\Platforms\LinkConnectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoint for Facebook — link-only (same shape as TikTok). We do NOT
// scrape; the user gives a username or profile/page URL and we store the
// canonical link. Single-tenant cache, no auth, no migration.
class FacebookController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'facebook';
    }

    // POST /api/platforms/facebook/connect — store the profile link for the user.
    public function connect(ConnectFacebookRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $selection = $this->normalize($validated['username']);
        // Empty username is only valid for numeric profile.php links and legacy
        // /pages/<Name>/<id> Page links; anything else with no handle is junk input.
        if ($selection['username'] === ''
            && ! str_contains($selection['url'], 'profile.php')
            && ! str_contains($selection['url'], '/pages/')) {
            return $this->error('Enter your Facebook username or profile URL.', 422);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/facebook/selection — the authenticated user's saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/facebook — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Accept a bare handle, a facebook.com/<handle> vanity URL, or a
    // facebook.com/profile.php?id=<id> link → {username, url}. Facebook vanity
    // URLs have no @ prefix; profile.php links have no vanity username, so we
    // keep the full path and leave username empty.
    /**
     * @return array{username:string, url:string}
     */
    private function normalize(string $input): array
    {
        $s = trim($input);

        if (preg_match('~facebook\.com/(.+)$~i', $s, $m)) {
            $path = trim($m[1], '/');

            if (str_starts_with(strtolower($path), 'profile.php')) {
                // Numeric profile link — no vanity username; keep the full path.
                return ['username' => '', 'url' => 'https://www.facebook.com/'.$path];
            }

            if (str_starts_with(strtolower($path), 'pages/')) {
                // Legacy Page link /pages/<Name>/<id> — the username is NOT the
                // first segment ("pages"); there's no vanity handle. Keep the
                // path (minus any query) and leave username empty.
                $clean = explode('?', $path)[0];

                return ['username' => '', 'url' => 'https://www.facebook.com/'.$clean];
            }

            // Vanity URL — first path segment is the username; drop query/trailing path.
            $username = explode('/', explode('?', $path)[0])[0];

            return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
        }

        $username = ltrim($s, '@');

        return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
    }
}
