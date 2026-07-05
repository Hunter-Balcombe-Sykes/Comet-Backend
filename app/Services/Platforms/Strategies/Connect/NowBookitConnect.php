<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// NowBookit connect: booking link → accountid + venueid read straight from the
// URL's query string (no fetch). Moved verbatim from NowBookitController.
class NowBookitConnect implements ConnectStrategy
{
    public function __construct(private readonly NowBookitService $service) {}

    public function resolve(string $input): ConnectResult
    {
        if (! $this->service->isNowBookitUrl($input)) {
            return ConnectResult::fail();
        }

        $ids = $this->service->parseIds($input);
        if ($ids === null) {
            return ConnectResult::fail('That link is missing the venue details. Use your NowBookit booking link that includes accountid and venueid.');
        }

        return ConnectResult::ok([
            'url' => $input,
            'accountId' => $ids['accountId'],
            'venueId' => $ids['venueId'],
            'name' => $this->service->nameFromUrl($input),
            'embedUrl' => $this->service->embedUrl($ids['accountId'], $ids['venueId']),
            'source' => 'manual',
        ]);
    }
}
