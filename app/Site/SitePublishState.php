<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;

/**
 * The ingest layer's one read of site.sites.is_published.
 *
 * Two raw cross-domain query-builder reads used to answer this, one of them
 * per dispatch call. This is a seam, not a cache: one stub point for tests,
 * one place to change when "published" stops being a single boolean — which
 * it already nearly isn't, since is_published and "publicly visible" diverged
 * in the public read path with the pre-account carve-out (2026-09-01).
 *
 * Bound scoped(), so the memo lives for one job / one request and is reset
 * between queue jobs. NOT a cache: nothing to invalidate, no TTL. Publish
 * state does not change mid-projection.
 */
final class SitePublishState
{
    /** @var array<string, bool|null> */
    private array $memo = [];

    /**
     * True published, false unpublished, NULL when the user has no site row.
     * Callers distinguish all three: a missing site is not an unpublished one.
     */
    public function isPublished(string $userId): ?bool
    {
        // array_key_exists, not ??=: a memoised null would otherwise re-query
        // on every call, which is the cost this class exists to remove.
        if (! array_key_exists($userId, $this->memo)) {
            $this->memo[$userId] = $this->read($userId);
        }

        return $this->memo[$userId];
    }

    private function read(string $userId): ?bool
    {
        $published = DB::table('site.sites')->where('user_id', $userId)->value('is_published');

        return $published === null ? null : (bool) $published;
    }
}
