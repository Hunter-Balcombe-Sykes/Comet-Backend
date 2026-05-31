<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoint for Facebook — link-only (same shape as TikTok). We do NOT
// scrape; the user gives a username or profile/page URL and we store the
// canonical link. Single-tenant cache, no auth, no migration.
class FacebookController extends ApiController
{
    use ManagesPlatformSelection;

    private const SELECTION_KEY = 'platforms.facebook.selection';

    protected function selectionKey(): string
    {
        return self::SELECTION_KEY;
    }

    // POST /api/platforms/facebook/connect — store the profile link.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:200']]);

        $selection = $this->normalize($validated['username']);
        // Empty username is only valid for numeric profile.php links; anything
        // else with no handle is junk input.
        if ($selection['username'] === '' && ! str_contains($selection['url'], 'profile.php')) {
            return $this->error('Enter your Facebook username or profile URL.', 422);
        }

        $this->writeSelection($selection);

        return $this->success($selection);
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

            // Vanity URL — first path segment is the username; drop query/trailing path.
            $username = explode('/', explode('?', $path)[0])[0];

            return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
        }

        $username = ltrim($s, '@');

        return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
    }
}
