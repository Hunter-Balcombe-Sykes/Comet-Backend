<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\ResDiaryService;
use Illuminate\Http\JsonResponse;

// ResDiary — connect by restaurant link; the keyless ResDiary booking widget
// embeds live availability + booking (no scraping, no auth). A pasted widget URL
// is embedded verbatim; a microsite/booking page is turned into the standard
// widget URL.
class ResDiaryController extends SingleSelectionPlatformController
{
    public function __construct(private readonly ResDiaryService $service) {}

    protected function platform(): string
    {
        return Platform::Resdiary->value;
    }

    protected function resourceClass(): string
    {
        return ResDiaryConnectionResource::class;
    }

    // POST /api/platforms/resdiary/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $url = $request->validated()['url'];

        if (! $this->service->isResDiaryUrl($url)) {
            return $this->error('Enter a ResDiary booking link (resdiary.com/...).', 422);
        }

        $embedUrl = $this->service->embedUrl($url);
        if ($embedUrl === null) {
            return $this->error("That doesn't look like a ResDiary booking page. Paste your ResDiary booking or widget link.", 422);
        }

        return $this->connected($user, [
            'url' => $url,
            'microsite' => $this->service->parseMicrosite($url),
            'name' => $this->service->nameFromUrl($url),
            'embedUrl' => $embedUrl,
            // A manual (re)connect un-tags a Google-Business-seeded row so it drops
            // out of the connect modal's "Automatically Synced" undo list.
            'source' => 'manual',
        ]);
    }
}
