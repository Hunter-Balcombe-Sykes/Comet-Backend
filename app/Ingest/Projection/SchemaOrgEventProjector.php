<?php

namespace App\Ingest\Projection;

/**
 * Landed schema.org event doc (Eventbrite AND Humanitix — one shared shape,
 * see App\Ingest\Support\SchemaOrgEvent) → the `event` item kind.
 *
 * Occurrence times: the vendors emit ISO strings carrying the event's LOCAL
 * offset ("2026-06-20T09:00:00+10:00"). starts_at_local keeps the verbatim
 * local string, starts_at_utc is derived from the embedded offset —
 * deterministic string math, no clock, so the projector stays pure. The IANA
 * zone is unknowable from an offset alone, hence timezone stays null with
 * zone_confidence 'offset_only'.
 */
class SchemaOrgEventProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'event';
    }

    public function project(RecordView $view): ?array
    {
        $name = $view->string('name');
        $url = $view->string('url');
        if ($name === null || $url === null) {
            return null;
        }

        $start = $view->string('start_date');
        $end = $view->string('end_date');

        $occurrence = array_filter([
            'starts_at_local' => $start,
            'ends_at_local' => $end,
            'starts_at_utc' => $this->toUtc($start),
            'zone_confidence' => $start === null ? null : 'offset_only',
        ], static fn ($v) => $v !== null);

        $place = array_filter([
            'venue_name' => $view->string('venue'),
            'locality' => $view->string('locality'),
        ], static fn ($v) => $v !== null);

        $priceMin = $view->float('price_min');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_link' => ['url' => $url],
                'f_occurrence' => $occurrence === [] ? null : $occurrence,
                'f_place' => $place === [] ? null : $place,
                'f_text' => $view->string('description') === null ? null : ['body' => $view->string('description')],
            ]),
            'offers' => $priceMin === null ? [] : [[
                'amount_minor' => (int) round($priceMin * 100),
                'currency' => $view->string('currency'),
                // The scrape sees the LOWEST tier of a multi-tier offer set —
                // "from" is the only honest qualifier for it.
                'qualifier' => $priceMin === 0.0 ? 'free' : 'from',
                'url' => $url,
            ]],
            'media' => $view->string('image') === null ? [] : [
                ['role' => 'cover', 'url' => $view->string('image')],
            ],
        ];
    }

    /** ISO-with-offset → UTC ISO; null when unparseable (pure string math). */
    private function toUtc(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
