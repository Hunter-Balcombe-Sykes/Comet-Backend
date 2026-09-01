<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11a (2026-09-01): /v1/facebook/profile/events → upcoming-event stubs.
// The list answer carries identity + start only (no cover, no ticket URL) —
// landing docs is the details endpoint's job (FacebookEventDetailsNormalizer);
// these stubs exist to enumerate WHICH events to fetch details for, keyed by
// FB's stable event id. Rows are SYNTHESIZED, never spread, so credits_* and
// vendor-only keys can never leak into a persisted payload.
//
// Trial-verified shape notes (recorded payload 2026-09-01, thetotehotel):
// `event_place` is nullable (an event created by a touring act often has no
// place attached); `start_timestamp` is unix UTC seconds and the only machine-
// readable time (day_time_sentence is prose); cancelled and past events ride
// in the same list — both are filtered here on the VENDOR's flags, never a
// clock, so the normalizer stays pure.
class FacebookEventsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/facebook/profile/events page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one upcoming id-bearing event — a husk must
     *                                         read as "vendor miss", never as an empty calendar.
     */
    public function events(array $body): ?array
    {
        $events = $body['events'] ?? null;
        if (! is_array($events)) {
            return null;
        }

        $rows = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $id = trim((string) ($event['id'] ?? ''));
            $name = is_string($event['name'] ?? null) ? trim($event['name']) : '';
            $start = $event['start_timestamp'] ?? null;
            if ($id === '' || $name === '' || ! is_numeric($start) || (int) $start <= 0) {
                continue;
            }
            if (($event['is_canceled'] ?? false) === true || ($event['is_past'] ?? false) === true) {
                continue;
            }

            $url = $event['url'] ?? null;
            $place = is_array($event['event_place'] ?? null) ? $event['event_place'] : [];
            $venue = $place['contextual_name'] ?? null;
            $city = $place['location']['reverse_geocode']['city'] ?? null;

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'url' => is_string($url) && $url !== '' ? $url : 'https://www.facebook.com/events/'.$id.'/',
                'start_timestamp' => (int) $start,
                'venue' => is_string($venue) && trim($venue) !== '' ? trim($venue) : null,
                'city' => is_string($city) && trim($city) !== '' ? trim($city) : null,
            ];
        }

        return $rows === [] ? null : $rows;
    }
}
