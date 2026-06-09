<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Eventbrite "Tickets" selection: the organiser url + name, the next event,
 * and the upcoming list. `next`/`upcoming` are scraped event objects passed
 * through verbatim — the controller computes them (and filters past events)
 * before wrapping; this Resource only allowlists the top-level keys.
 *
 * `$this->resource` is the selection ARRAY.
 */
class EventbriteConnectionResource extends ApiResource
{
    /**
     * @return array{url:mixed, organiser:mixed, next:mixed, upcoming:mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'organiser' => $this->resource['organiser'] ?? null,
            'next' => $this->resource['next'] ?? null,
            'upcoming' => $this->resource['upcoming'] ?? [],
        ];
    }
}
