<?php

namespace App\Routing;

use Illuminate\Support\Str;

/**
 * PlacementPolicy's decision about one projection, for one caller context.
 * Carries the reason in every non-Place case so the suggestions inbox, the
 * staff stuck-intents view, and `routing:reproject` all read the same words.
 */
final readonly class Placement
{
    public function __construct(
        public Verdict $verdict,
        public ?string $surfaceKey,
        public ?string $identifier,
        public ?string $blockReason = null,
        public ?string $explanation = null,
        public ?string $conflictingConnectionId = null,
        /**
         * A human-readable name for $identifier, when the lane that produced
         * this placement happened to carry one.
         *
         * Deliberately NOT on Projection. A projection is what the PURE
         * projector emits — f(Iri, Rulepack), no I/O — and routing:reproject
         * is only a diff tool because that stays true. A shop name exists only
         * because a probe made a network call, so it joins the pipeline here,
         * at the decision, rather than being back-filled into the input.
         */
        public ?string $identifierLabel = null,
        /**
         * Which band this landed in, 'auto' or 'suggest' — set only on
         * Place/Choose, the verdicts an inbox card can render.
         *
         * 'auto' now means the matched rule CAPTURED an identifier: we can name
         * the account, so the row arrives pre-ticked (owner, 2026-09-03). It
         * used to mean "scored above the class's auto threshold", and the
         * accompanying `confidence` int was deleted with the rest of that
         * system — a band derived from a number nobody could tune was a
         * pre-tick nobody could explain.
         */
        public ?string $band = null,
        /**
         * The identified thing's own icon URL (a store's favicon/logo off the
         * probe that fetched it) — same network-born rationale as
         * identifierLabel above, surfaced so a suggestion card can wear the
         * store's mark rather than the provider's (owner, 2026-09-03).
         */
        public ?string $identifierIcon = null,
    ) {}

    /** This placement with a display name attached; null leaves it as-is. */
    public function withLabel(?string $label): self
    {
        $label = $label === null ? null : trim($label);

        if ($label === null || $label === '') {
            return $this;
        }

        // Bounded: this arrives from a scraped upstream document (a probe's
        // shop_name), is persisted, and is served on every inbox GET. The
        // column is unbounded text and nothing downstream truncates.
        $label = Str::limit($label, 200, '');

        return new self(
            $this->verdict,
            $this->surfaceKey,
            $this->identifier,
            $this->blockReason,
            $this->explanation,
            $this->conflictingConnectionId,
            $label,
            $this->band,
            $this->identifierIcon,
        );
    }

    public function withIcon(?string $icon): self
    {
        $icon = $icon === null ? null : trim($icon);

        // Only a real absolute URL — this is scraped input that ends up in an
        // <img src> on the dashboard; anything else is dropped, not "fixed".
        if ($icon === null || $icon === '' || ! str_starts_with($icon, 'http')) {
            return $this;
        }

        $icon = Str::limit($icon, 2000, '');

        return new self(
            $this->verdict,
            $this->surfaceKey,
            $this->identifier,
            $this->blockReason,
            $this->explanation,
            $this->conflictingConnectionId,
            $this->identifierLabel,
            $this->band,
            $icon,
        );
    }

    public static function reject(string $reason, ?string $surfaceKey = null): self
    {
        return new self(Verdict::Reject, $surfaceKey, null, $reason);
    }
}
