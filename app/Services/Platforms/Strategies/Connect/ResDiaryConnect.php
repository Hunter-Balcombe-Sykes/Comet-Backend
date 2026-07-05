<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// ResDiary connect: booking/widget link → standard widget URL (no fetch).
// Moved verbatim from ResDiaryController.
class ResDiaryConnect implements ConnectStrategy
{
    public function __construct(private readonly ResDiaryService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isResDiaryUrl($input)) {
            return ConnectResult::fail();
        }

        $embedUrl = $this->service->embedUrl($input);
        if ($embedUrl === null) {
            return ConnectResult::fail("That doesn't look like a ResDiary booking page. Paste your ResDiary booking or widget link.");
        }

        return ConnectResult::ok([
            'url' => $input,
            'microsite' => $this->service->parseMicrosite($input),
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $embedUrl,
            // A manual (re)connect un-tags a Google-Business-seeded row so it drops
            // out of the connect modal's "Automatically Synced" undo list.
            'source' => 'manual',
        ]);
    }
}
