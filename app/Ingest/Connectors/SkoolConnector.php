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
 * Skool community card from og: tags — skool.com/{slug}/about is public even
 * for private communities; the bare root works for public ones. Plan §11's
 * explicitly-restored row: a community card preserved as a `channel` item,
 * no feed to poll behind it, hence the week-long default interval (the "no
 * cron" of the legacy connect-time fetch, translated into scheduler terms).
 *
 * The connect path itself is FeatureAvailability-kill-switched right now —
 * that gates user-facing connect, not this code's existence.
 */
class SkoolConnector implements Connector
{
    /** og:title values that mean "not a community page" (signup wall / chrome). */
    private const NON_COMMUNITY_TITLES = ['skool: sign up', 'skool', 'skool: discover communities or create your own'];

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('skool'),
            identifierKind: 'url',
            hosts: ['skool.com', 'www.skool.com'],
            streams: [
                'community' => new StreamSpec(
                    name: 'community',
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
        $canonicalUrl = rtrim(trim($pull->identifier), '/');
        $slug = strtolower(trim((string) parse_url($canonicalUrl, PHP_URL_PATH), '/'));

        // The about page answers for private communities too; the root only
        // for public ones.
        $lastStatus = null;
        foreach ([$canonicalUrl.'/about', $canonicalUrl] as $candidate) {
            $response = $io->get($candidate);
            $lastStatus = $response['status'];
            if ($response['status'] !== 200 || $response['body'] === '') {
                continue;
            }

            $name = Html::metaContent($response['body'], 'og:title');
            if (! is_string($name) || in_array(strtolower(trim($name)), self::NON_COMMUNITY_TITLES, true)) {
                // Product chrome, not the community — try the next candidate.
                continue;
            }

            yield new Record('community', $slug !== '' ? $slug : $canonicalUrl, array_filter([
                'name' => trim($name),
                'url' => $canonicalUrl,
                'handle' => $slug !== '' ? $slug : null,
                'avatar' => Html::metaContent($response['body'], 'og:image'),
                'description' => Html::metaContent($response['body'], 'og:description'),
            ], static fn ($v) => $v !== null));
            yield new Covered('community', Coverage::exhaustive());

            return;
        }

        // Neither candidate yielded a community card: unreachable or walled,
        // never "the community is gone" (C5).
        yield new Unavailable('no community card on the about page or root', $lastStatus);
    }
}
