<?php

namespace App\Services\Content;

use App\Services\Platforms\EventPageReader;
use App\Services\Platforms\MediaPageReader;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Site\Pools\PoolRegistry;

// What a pasted link LOOKS LIKE, before anything is created — pure grammar,
// no fetch, so the add sheets can show "this looks like a Spotify track —
// add it on Listen" in STEP 1, ahead of the Continue button (owner,
// 2026-08-20: the cross-pool guidance arriving as a 422 toast after submit
// was the complaint). The pool endpoints keep their own 422s as the
// backstop; this is the same grammar surfaced earlier.
class PastedLinkClassifier
{
    public function __construct(
        private readonly MediaPageReader $media,
        private readonly EventPageReader $events,
        private readonly WebsiteLinkHarvester $harvester,
    ) {}

    /**
     * @return array{
     *   belongsTo: array{pool: string, kind: string, pageLabel: string}|null,
     *   account: string|null,
     * }
     */
    public function classify(string $url): array
    {
        $item = $this->media->classifyItem($url);
        if ($item !== null) {
            return ['belongsTo' => $this->belongsTo($item['kind']), 'account' => null];
        }

        $account = $this->media->accountPlatformLabel($url)
            ?? $this->events->organiserPlatformLabel($url);
        if ($account !== null) {
            return ['belongsTo' => null, 'account' => $account];
        }

        // Events grammar lives in the harvester (pure — regex arms plus the
        // no-fetch catalog projection); only the event/organiser categories
        // are read here, everything else is not this classifier's business.
        $classified = $this->harvester->classify($url);
        if (($classified['category'] ?? null) === 'event') {
            return ['belongsTo' => $this->belongsTo('event'), 'account' => null];
        }
        if (($classified['category'] ?? null) === 'event-organiser') {
            return ['belongsTo' => null, 'account' => (string) $classified['label']];
        }

        return ['belongsTo' => null, 'account' => null];
    }

    /** @return array{pool: string, kind: string, pageLabel: string}|null */
    private function belongsTo(string $kind): ?array
    {
        $pool = PoolRegistry::poolForKind($kind);
        if ($pool === null) {
            return null;
        }

        return [
            'pool' => $pool,
            'kind' => $kind,
            'pageLabel' => PoolRegistry::PAGE_LABELS[$pool] ?? $pool,
        ];
    }
}
