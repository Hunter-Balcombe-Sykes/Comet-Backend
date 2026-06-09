<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectTiktokRequest;
use App\Http\Resources\Platforms\LinkConnectionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoint for TikTok — link-only. We do NOT scrape (TikTok's
// anti-bot makes server-side profile fetching unreliable). The user gives a
// username or profile URL; we normalise to a handle and store the canonical
// profile link. Single-tenant cache, no auth, no migration.
class TiktokController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'tiktok';
    }

    // POST /api/platforms/tiktok/connect — store the profile link for the user.
    public function connect(ConnectTiktokRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $username = $this->normalizeUsername($validated['username']);
        if ($username === '') {
            return $this->error('Enter your TikTok username or profile URL.', 422);
        }

        $selection = [
            'username' => $username,
            'url' => 'https://www.tiktok.com/@'.$username,
        ];
        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/tiktok/selection — the authenticated user's saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/tiktok — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

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
