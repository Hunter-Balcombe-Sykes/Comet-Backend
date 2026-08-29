<?php

namespace App\Exceptions\Ingest;

use RuntimeException;

// Reported to Nightwatch whenever ProjectionWriter::foldCollections() lets the merge-media
// cap eat incoming item_media rows (#W1-OBS-2). Log::warning('content.merge_fold.media_capped')
// is a breadcrumb and does not reach Nightwatch (CLAUDE.md); report() is the house pattern
// for exactly this — see AbandonedEffectException.
//
// A capped drop is RECOVERABLE by construction: only connector-origin rows can be dropped
// (manual origins are exempt), and the next reprojection re-derives them from
// ingest.record_versions through the uncapped replaceCollections() path. A sustained run of
// these therefore is not data loss, it is a mis-set cap — and that is an operator event.
class MergeFoldMediaDroppedException extends RuntimeException
{
    public function __construct(
        public readonly string $userId,
        public readonly string $itemId,
        public readonly int $dropped,
    ) {
        parent::__construct(sprintf(
            'Merge fold dropped %d media row%s onto item %s (user %s) — the merge_media_cap bit.',
            $dropped,
            $dropped === 1 ? '' : 's',
            $itemId,
            $userId,
        ));
    }
}
