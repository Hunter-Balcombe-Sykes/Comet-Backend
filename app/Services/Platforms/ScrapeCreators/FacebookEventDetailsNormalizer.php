<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11a (2026-09-01): /v1/facebook/event/details → the ONE landed event doc
// shape Eventbrite and Humanitix already share (App\Ingest\Support\
// SchemaOrgEvent's output vocabulary), so the existing SchemaOrgEventProjector
// serves FB events unchanged — no new pool semantics, the third source of the
// `event` item kind. The doc is SYNTHESIZED, never spread: credits_* and the
// dozens of vendor-only keys (hosts, RSVP counts, facepiles) never persist.
//
// Trial-verified shape notes (recorded payload 2026-09-01, Shonen Knife @ The
// Tote): `start_time`/`end_time` arrive null even when the event has a time —
// `start_timestamp` (unix UTC seconds) is the real field; `price` is prose
// junk ("Tickets") and `price_info` null, so no offer is synthesized;
// `ticket_url` (Oztix here) is the actionable link and outranks the FB event
// page as the doc URL — "ticket url" is the item contract's link. The local
// offset is derived from `day_time_sentence`'s wall clock vs the UTC instant
// (deterministic string math, no clock, mirroring the projector's
// offset_only doctrine); an unparseable sentence degrades to +00:00 — the
// UTC instant stays correct, only the local display time coarsens.
class FacebookEventDetailsNormalizer
{
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * @param  array<string, mixed>  $body  one /v1/facebook/event/details answer
     * @return array<string, mixed>|null null unless the answer positively
     *                                   carries a named, dated, uncancelled event — a NotFound husk
     *                                   (success:true, no event fields) must read as "vendor miss".
     */
    public function doc(array $body): ?array
    {
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $start = $body['start_timestamp'] ?? null;
        if ($name === '' || ! is_numeric($start) || (int) $start <= 0) {
            return null;
        }
        if (($body['is_canceled'] ?? false) === true) {
            return null;
        }

        $id = trim((string) ($body['id'] ?? ''));
        $eventUrl = is_string($body['url'] ?? null) && $body['url'] !== ''
            ? $body['url']
            : ($id !== '' ? 'https://www.facebook.com/events/'.$id.'/' : null);
        if ($eventUrl === null) {
            return null;
        }

        $offset = $this->offsetSeconds($body['day_time_sentence'] ?? null, (int) $start);

        $ticketUrl = $body['ticket_url'] ?? null;
        $ticketUrl = is_string($ticketUrl) && preg_match('~^https?://~i', $ticketUrl) ? $ticketUrl : null;

        $end = $body['end_timestamp'] ?? null;

        $place = is_array($body['event_place'] ?? null) ? $body['event_place'] : [];
        $venue = $place['name'] ?? null;
        if (! is_string($venue) || trim($venue) === '') {
            $venue = is_string($body['location_name'] ?? null) ? $body['location_name'] : null;
        }

        // "Melbourne, Victoria, Australia" → the locality is the first segment.
        $city = is_string($body['city'] ?? null) ? trim(explode(',', $body['city'])[0]) : '';

        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
        $image = $body['cover_photo_url'] ?? null;

        return array_filter([
            'name' => $name,
            'url' => $ticketUrl ?? $eventUrl,
            'start_date' => $this->localIso((int) $start, $offset),
            'end_date' => is_numeric($end) && (int) $end > 0 ? $this->localIso((int) $end, $offset) : null,
            'venue' => is_string($venue) ? (trim($venue) ?: null) : null,
            'locality' => $city !== '' ? $city : null,
            'description' => $description !== '' ? $description : null,
            'image' => is_string($image) && $image !== '' ? $image : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Local offset = the sentence's wall clock read as-if-UTC minus the real
     * UTC instant, rounded to the quarter hour ("Wednesday, October 14, 2026
     * at 7:30 PM AEDT" vs 1791966600 → +11:00). Beyond ±14h (no real zone)
     * the sentence is treated as unparseable.
     */
    private function offsetSeconds(mixed $sentence, int $startTimestamp): int
    {
        if (! is_string($sentence)
            || ! preg_match('~([A-Za-z]+) (\d{1,2}), (\d{4}) at (\d{1,2}):(\d{2}) (AM|PM)~', $sentence, $m)) {
            return 0;
        }

        $month = self::MONTHS[strtolower($m[1])] ?? null;
        if ($month === null) {
            return 0;
        }

        $hour = ((int) $m[4]) % 12 + ($m[6] === 'PM' ? 12 : 0);
        $wallAsUtc = gmmktime($hour, (int) $m[5], 0, $month, (int) $m[2], (int) $m[3]);

        $offset = (int) round(($wallAsUtc - $startTimestamp) / 900) * 900;

        return abs($offset) <= 14 * 3600 ? $offset : 0;
    }

    /** Unix UTC seconds + offset → the ISO-with-offset string the projector expects. */
    private function localIso(int $timestamp, int $offset): string
    {
        $sign = $offset < 0 ? '-' : '+';
        $abs = abs($offset);

        return gmdate('Y-m-d\TH:i:s', $timestamp + $offset)
            .sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}
