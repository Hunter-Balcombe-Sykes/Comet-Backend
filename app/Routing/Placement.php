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
        );
    }

    public static function reject(string $reason, ?string $surfaceKey = null): self
    {
        return new self(Verdict::Reject, $surfaceKey, null, $reason);
    }
}
