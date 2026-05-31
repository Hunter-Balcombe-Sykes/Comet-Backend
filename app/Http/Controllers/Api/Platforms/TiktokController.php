<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoint for TikTok — link-only. We do NOT scrape (TikTok's
// anti-bot makes server-side profile fetching unreliable). The user gives a
// username or profile URL; we normalise to a handle and store the canonical
// profile link. Single-tenant cache, no auth, no migration.
class TiktokController extends ApiController
{
    use ManagesPlatformSelection;

    private const SELECTION_KEY = 'platforms.tiktok.selection';

    protected function selectionKey(): string
    {
        return self::SELECTION_KEY;
    }

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
        $this->writeSelection($selection);

        return $this->success($selection);
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
