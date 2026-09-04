<?php

namespace App\Http\Requests\Concerns;

/**
 * Wire compatibility shim for the site_media `pool` → `usage` rename (2026-09-04).
 *
 * The upload endpoints named this field `pool` while the column did. The column
 * is `usage` now — "pool" means a public page section (content.* pools) and
 * nothing else. The wire follows, but not in the same deploy as the dashboard:
 * this accepts EITHER spelling and validates the new one.
 *
 * `usage` wins when a client sends both, so a migrated dashboard is never held
 * hostage by a stale `pool` its own form state left behind.
 *
 * REMOVE ME once PartnaAu/partna-frontend's `refactor/site-media-usage-rename`
 * is merged and deployed — it already sends `usage`. Deleting this trait, its two
 * `use` lines and the `pool` alias in SiteMediaResource is the whole cleanup.
 */
trait AcceptsLegacyPoolField
{
    /**
     * Fold a legacy `pool` input onto `usage`, lower-cased and trimmed.
     * Safe to call when neither key is present.
     */
    protected function foldLegacyPoolField(): void
    {
        $value = $this->input('usage') ?? $this->input('pool');

        if (is_string($value)) {
            $this->merge(['usage' => strtolower(trim($value))]);
        }
    }

    /**
     * The field name the client actually sent, so a 422 points at the key they
     * typed rather than one they have never heard of.
     */
    protected function usageFieldLabel(): string
    {
        return $this->input('usage') !== null ? 'usage' : 'pool';
    }
}
