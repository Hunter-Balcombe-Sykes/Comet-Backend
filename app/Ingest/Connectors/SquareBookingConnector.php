<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Platforms\SquareBookingPage;

/**
 * Square Appointments (2026-09-02). One free GET of the buyer widget JSON
 * (SquareBookingPage's docblock has the contract) lands the booking page's
 * services as the `service` kind, narrowed to the team member the
 * connection URL's team_member_id names — that param IS the selection, so
 * there is no selection_ref: change the URL, and the provisioner re-dates
 * the source. Catalogue-with-deletes, Fresha's semantics: the document is
 * the whole menu. Square Online MENUS are a different surface
 * (square.order) on the brand connector (SquareMenuConnector).
 */
class SquareBookingConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('square_book'),
            identifierKind: 'url',
            hosts: ['app.squareup.com', 'book.squareup.com'],
            streams: [
                'services' => new StreamSpec(
                    name: 'services',
                    target: 'service',
                    profile: SourceProfile::Catalogue,
                    requires: ['name'],
                    volatile: [],
                    orderField: null,
                    deletesOnExhaustive: true,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $parsed = SquareBookingPage::parseUrl($pull->identifier);
        if ($parsed['merchant'] === null) {
            yield new Unavailable('square booking url carries no merchant id');

            return;
        }

        $response = $io->get(SquareBookingPage::widgetUrl($parsed['merchant'], $parsed['unit']), ['Accept' => 'application/json']);
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("square widget returned {$response['status']}", $response['status']);

            return;
        }
        $doc = json_decode((string) $response['body'], true);
        if (! is_array($doc) || ! is_array($doc['services'] ?? null)) {
            yield new Unavailable('square widget response carried no services[] — shape may have changed', $response['status']);

            return;
        }

        $teamMember = $parsed['teamMember'];
        $staffId = null;
        if ($teamMember !== null) {
            $staffId = SquareBookingPage::staffIdFor($doc, $teamMember);
            if ($staffId === null) {
                yield new Note('team_member_not_found', "team member {$teamMember} is not on this booking page; landing the whole menu");
                $teamMember = null;
            }
        }

        $unit = $parsed['unit'] ?? SquareBookingPage::unitToken($doc);
        $items = SquareBookingPage::services($doc, $staffId);
        if ($items === []) {
            yield new Note('empty_menu', 'No bookable services on the Square booking page');

            return;
        }
        foreach ($items as $item) {
            $item['url'] = $unit === null
                ? $pull->identifier
                : SquareBookingPage::bookingDeepLink($parsed['merchant'], $unit, (string) $item['service_id'], $teamMember);
            yield new Record('services', (string) $item['service_id'], $item);
        }
        yield new Covered('services', Coverage::exhaustive());
    }
}
