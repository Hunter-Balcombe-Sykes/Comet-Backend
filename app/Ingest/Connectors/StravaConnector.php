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
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Support\Html;

/**
 * Strava club card (strava.com/clubs/{slug-or-id}) — clubs server-render og:
 * tags plus a member count with no login wall; athlete profiles ARE walled,
 * so clubs are the one keyless Strava surface. Plan §11's explicitly-restored
 * row: a community card as a `channel` item, no feed behind it.
 *
 * og:image is the 124px "large" avatar rendition; a ~416px "original"
 * usually sits beside it on the CDN, so it is probed and preferred — the one
 * extra request this connector makes, and why the CDN host is on the
 * manifest.
 */
class StravaConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('strava'),
            identifierKind: 'url',
            hosts: ['strava.com', 'www.strava.com', 'dgalywyr863hv.cloudfront.net'],
            streams: [
                'club' => new StreamSpec(
                    name: 'club',
                    target: 'channel',
                    profile: SourceProfile::Identity,
                    requires: ['name', 'url'],
                    volatile: [],
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 604800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $url = rtrim(trim($pull->identifier), '/');
        $response = $io->get($url);

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("club page returned {$response['status']}", $response['status']);

            return;
        }

        $title = Html::metaContent($response['body'], 'og:title');
        if ($title === null) {
            // A 200 without og tags is a wall or layout change, never a
            // deleted club (C5).
            yield new Unavailable('club page carried no og:title');

            return;
        }

        // og:title is "City, Region | Club Name" — location first, name last;
        // clubs without a location are just the name.
        $name = $title;
        $location = null;
        if (str_contains($title, '|')) {
            $pieces = array_map('trim', explode('|', $title));
            $name = array_pop($pieces) ?: $title;
            $location = implode(' | ', $pieces) ?: null;
        }

        $members = null;
        if (preg_match('~([\d,.]+)\s+members~i', $response['body'], $m)) {
            $members = (int) str_replace([',', '.'], '', $m[1]);
        }

        $slug = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));

        yield new Record('club', $slug !== '' ? $slug : $url, array_filter([
            'name' => $name,
            'url' => $url,
            'handle' => $slug !== '' ? $slug : null,
            'avatar' => $this->bestAvatar(Html::metaContent($response['body'], 'og:image'), $io),
            'description' => Html::metaContent($response['body'], 'og:description'),
            'location' => $location,
            'followers' => $members,
        ], static fn ($v) => $v !== null));
        yield new Covered('club', Coverage::exhaustive());
    }

    /** Prefer the ~416px "original" rendition when the CDN serves it. */
    private function bestAvatar(?string $image, Io $io): ?string
    {
        if ($image === null) {
            return null;
        }
        if (preg_match('~^(https://dgalywyr863hv\.cloudfront\.net/pictures/clubs/.+/)large\.(jpe?g|png)$~i', $image, $m)) {
            $original = $m[1].'original.'.$m[2];
            if ($io->get($original)['status'] === 200) {
                return $original;
            }
        }

        return $image;
    }
}
