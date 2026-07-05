<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// OpenTable connect: restaurant link → rid read from the URL; the keyless
// widget embeds availability. OpenTable WAF-blocks our servers, so slug-only
// links are rejected with a nudge. Moved verbatim from OpenTableController.
class OpenTableConnect implements ConnectStrategy
{
    public function __construct(private readonly OpenTableService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isOpenTableUrl($input)) {
            return ConnectResult::fail();
        }

        $rid = $this->service->parseRid($input);
        if ($rid === null) {
            return ConnectResult::fail("That link doesn't include the restaurant id. Use the profile link with the number — opentable.com.au/restaurant/profile/123456.");
        }

        return ConnectResult::ok([
            'url' => $input,
            'rid' => $rid,
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $this->service->embedUrl($rid, $this->service->hostOf($input)),
            // A manual (re)connect un-tags a Google-Business-seeded row so it drops
            // out of the connect modal's "Automatically Synced" undo list.
            'source' => 'manual',
        ]);
    }
}
