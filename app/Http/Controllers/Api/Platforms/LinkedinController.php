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

// Link-only LinkedIn connect — same shape as Facebook/TikTok. Accepts
// personal (/in/), company (/company/), and school (/school/) URLs, or a
// bare vanity slug (assumed personal). No scraping, no refresh.
class LinkedinController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'linkedin';
    }

    // POST /api/platforms/linkedin/connect — store the profile link.
    public function connect(ConnectSocialLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $selection = $this->normalize($request->validated()['username']);
        if ($selection === null) {
            return $this->error('Enter your LinkedIn profile URL (linkedin.com/in/yourname).', 422);
        }

        $this->writeConnection($user, $selection);

        return $this->success((new LinkConnectionResource($selection))->resolve());
    }

    // GET /api/platforms/linkedin/selection — the saved link.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new LinkConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/linkedin — clear the connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // Accept linkedin.com/in|company|school|pub/<slug> URLs (any scheme or
    // none, locale subdomains like au.linkedin.com, trailing junk tolerated)
    // or a bare slug → {username, url}. Slugs are percent-decoded — LinkedIn
    // vanity slugs can carry unicode.
    /**
     * @return array{username:string, url:string}|null
     */
    private function normalize(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~linkedin\.com/(in|company|school|pub)/([^/?#]+)~i', $s, $m)) {
            // Legacy /pub/ profiles redirect to /in/ — store the modern form.
            $kind = strtolower($m[1]) === 'pub' ? 'in' : strtolower($m[1]);
            $slug = rawurldecode($m[2]);
        } elseif (str_contains($s, 'linkedin.com')) {
            // A linkedin.com URL without a recognised profile path — reject
            // rather than storing a feed/jobs link.
            return null;
        } else {
            $kind = 'in';
            $slug = PlatformInput::token($s);
        }

        if (! preg_match('~^[\p{L}\p{N}._\-]{2,100}$~u', $slug)) {
            return null;
        }

        return [
            'username' => $slug,
            'url' => 'https://www.linkedin.com/'.$kind.'/'.rawurlencode($slug).'/',
        ];
    }
}
