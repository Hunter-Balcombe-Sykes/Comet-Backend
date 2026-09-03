<?php

namespace App\Routing;

/**
 * The projector's answer for one Iri: which surface (if any), what identifier
 * was captured, and why.
 *
 * ── What used to be here, and why it is gone (2026-09-03) ───────────────────
 *
 * This carried `confidence` (0–100) and `margin` (the gap to the runner-up),
 * and PlacementPolicy compared them against a per-class threshold table to
 * decide whether a link could be written. The whole apparatus is deleted.
 *
 * It was arithmetic standing in for a question it could not actually answer.
 * The score was a sum of structural facts — +35 for a path pattern, +20 for a
 * subdomain, +15 per required query param, ±8 for evidence strength, −8 for a
 * deep path under a host-only rule — and then a threshold turned that sum back
 * into a yes/no. Every one of those adjustments had to be re-tuned whenever a
 * brand's URL shape changed, and the tuning was never right for long: the
 * st-ali-bali link projected cleanly to the correct restaurant, scored 59
 * against a threshold of 55 with a 10-point harvest penalty, and was dropped.
 * A +20 patch was added to buy query-captured identifiers "parity" with
 * path-captured ones — a number chosen to make one case come out right.
 *
 * The question the thresholds were being asked is structural and has a
 * structural answer, which `LinkValidity` now gives directly: did the rule that
 * matched constrain anything beyond the brand's registrable domain? If it did,
 * the link names an account and can be suggested. If it did not, it names a
 * brand, and no score was ever going to change that.
 *
 * `contested` is what replaced `margin`, and it is the same question asked
 * honestly: did a rule for a DIFFERENT surface also match? That is ambiguity.
 * A gap of nine points between two rules was not.
 */
final readonly class Projection
{
    public function __construct(
        public ?string $surfaceKey,
        public ?string $detectorId,
        /** @var array<string, string> */
        public array $captures,
        public ?string $identifier,
        public ?string $reason,
        /**
         * A detector for a different surface matched this URL too, so which
         * brand this link belongs to is genuinely open. Never auto-applied.
         */
        public bool $contested = false,
        /** @var list<array{surface: string, detector: string}> */
        public array $alternatives = [],
    ) {}

    public function matched(): bool
    {
        return $this->surfaceKey !== null;
    }

    public static function none(string $reason): self
    {
        return new self(null, null, [], null, $reason);
    }
}
