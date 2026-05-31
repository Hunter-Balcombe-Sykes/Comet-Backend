<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoint for TikTok — link-only. We do NOT scrape (TikTok's
// anti-bot makes server-side profile fetching unreliable). The user gives a
// username or profile URL; we normalise to a handle and store the canonical
// profile link. Single-tenant cache, no auth, no migration.
class TiktokController extends ApiController
{
    private const SELECTION_KEY = 'platforms.tiktok.selection';

    private const CACHE_TTL_DAYS = 30;

    // POST /api/platforms/tiktok/connect — store the profile link.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:200']]);

        $username = $this->normalizeUsername($validated['username']);
        if ($username === '') {
            return $this->error('Enter your TikTok username or profile URL.', 422);
        }

        $selection = [
            'username' => $username,
            'url' => 'https://www.tiktok.com/@'.$username,
        ];
        Cache::put(self::SELECTION_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/tiktok/selection
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_KEY)]);
    }

    // DELETE /api/platforms/tiktok
    public function forget(): JsonResponse
    {
        Cache::forget(self::SELECTION_KEY);

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Accept a bare handle, @handle, or a tiktok.com/@handle URL → bare handle.
    private function normalizeUsername(string $input): string
    {
        $s = trim($input);
        if (preg_match('~tiktok\.com/@([A-Za-z0-9._]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }
}
