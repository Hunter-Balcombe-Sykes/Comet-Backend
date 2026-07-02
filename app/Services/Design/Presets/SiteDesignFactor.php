<?php

namespace App\Services\Design\Presets;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;

/**
 * A design factor detected from SITE-LEVEL state rather than a single
 * integration connection — either non-connection sources (the workplace's
 * previous-website analysis) or aggregates ACROSS many connections (the
 * outside-connected-websites mode vote). Runs on every resolve (auto
 * semantics; no one-shot freezing — "frozenness" lives in the stored analysis,
 * which only changes when its source changes). detect() must be cheap and
 * fetch nothing: it reads stored analysis state only.
 */
interface SiteDesignFactor
{
    /** Stable identifier → contributions.source (the sweep/upsert unit). */
    public function key(): string;

    /** Plain-text contributions.integration label (provenance only). */
    public function integrationLabel(): string;

    /** Higher wins a contested column. */
    public function priority(): int;

    /**
     * @return array<string, string> sparse [design_kit column => value]; [] when nothing applies
     */
    public function detect(User $user, Site $site): array;
}
