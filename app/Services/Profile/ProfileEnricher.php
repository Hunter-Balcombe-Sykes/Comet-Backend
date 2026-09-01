<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;

/**
 * The ONE enrichment step every pre-account source runs (2026-08-28).
 *
 * Before this existed, BioIntelligence was reachable from the Instagram lane
 * only, so the whole quality bar — auto-About, contact extraction, mention
 * chains — landed on one of the two account types. Sources now call this
 * instead of the model directly, and PreAccountEnrichmentSeamTest holds every
 * registered generator to it.
 *
 * WHAT IT WRITES: the About and the public contact pair, FILL-IF-EMPTY. That
 * only-fill-empty rule is the whole ownership contract — an owner-authored (or
 * structurally-sourced, e.g. Google's phone) value is never touched. Callers
 * that have a structured value should apply it BEFORE calling enrich(), and
 * fill-if-empty then preserves it without a special case.
 *
 * WHAT IT DOES NOT WRITE: names. Instagram's rule (AI display name, falling back
 * to PersonNameParser) and Google's (the listing name, word-trimmed, capability-
 * gated) disagree, so each caller applies names itself from the returned result.
 */
class ProfileEnricher
{
    public function __construct(private readonly BioIntelligence $bioIntelligence) {}

    /**
     * One gated pass over the source's bio text. Never throws: enrichment is a
     * quality lift on a build that must complete regardless.
     */
    public function enrich(User $user, BioSource $source): BioIntel
    {
        $intel = $this->analyse($source);

        $this->apply($user, $intel);

        return $intel;
    }

    /**
     * Item 1a: the ANALYSIS half alone, user-free — phase one of a build now
     * needs the cleaned name BEFORE any user exists (it seeds the handle), so
     * the model pass and the user write split. This stays the ONLY route to
     * BioIntelligence (PreAccountEnrichmentSeamTest); a caller that analyses
     * here must apply the SAME result via applyIntel(), never re-analyse.
     */
    public function analyse(BioSource $source): BioIntel
    {
        // No bio text is the common case for a Google listing (74% of real ones
        // carry no description at all) — skip the model rather than pay for a
        // call whose every field the gates would null anyway.
        if (! $source->hasBiography()) {
            return BioIntel::empty();
        }

        return BioIntel::fromArray($this->bioIntelligence->analyse(
            $source->handle,
            $source->fullName,
            $source->biography,
            $source->businessCategory,
        ));
    }

    /**
     * Apply a result this build ALREADY paid for. The second Instagram caller
     * (InstagramIdentitySync, reached via the connection seeder) uses this so the
     * fill rules live in one place and no second model call is made.
     */
    public function applyIntel(User $user, BioIntel $intel): void
    {
        $this->apply($user, $intel);
    }

    /** Fill-if-empty onto the user row; saves only when something actually changed. */
    private function apply(User $user, BioIntel $intel): void
    {
        $fills = [
            'bio' => $intel->about,
            'public_contact_email' => $intel->email,
            'public_contact_number' => $intel->phone,
        ];

        $changed = false;
        foreach ($fills as $column => $value) {
            if ($value === null || trim((string) $user->{$column}) !== '') {
                continue;
            }
            $user->{$column} = $value;
            $changed = true;
        }

        if ($changed) {
            $user->save();
        }
    }
}
