<?php

namespace App\Services\PreAccount\Generators;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\PreAccount\SourceGenerationException;

// Contract every pre-account source (Instagram, Google Business, ...) implements.
// A third source is one new class registered in config('partna.pre_account.generators')
// — never a branch added to PreAccountBuildService itself.
interface SiteSourceGenerator
{
    /** Canonicalize the typed ref (IG: strip @/trim/lowercase). @throws \InvalidArgumentException */
    public function normalizeRef(string $raw): string;

    /** Dedupe key for pre_account_builds.source_ref_lc (IG: same as ref; GBP: exact place_id). */
    public function dedupeKey(string $normalizedRef): string;

    /** Seed for handle/subdomain/display-name (IG: the handle; GBP: source_name — F1). */
    public function handleSeed(string $normalizedRef, ?string $sourceName): string;

    /**
     * Populate user profile fields + site content from the source.
     *
     * $autoConnectBooking is TRUE only for a staff/ManyChat build, where nobody
     * is present to answer "whose menu is this?" — every other origin shows the
     * account holder a picker instead. Defaults FALSE so a new source that
     * ignores the parameter cannot silently auto-connect on a user's behalf.
     *
     * @throws SourceGenerationException
     */
    public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false): void;
}
