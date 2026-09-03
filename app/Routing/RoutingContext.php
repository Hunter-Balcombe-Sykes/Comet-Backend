<?php

namespace App\Routing;

use App\Models\Core\User\User;

/**
 * Everything about the CALLER that a placement decision may consider — and
 * nothing about the link itself. Keeping this separate from Iri/Projection is
 * what makes the projector pure and reproducible: the same URL always
 * projects identically, and only placement varies by who is asking.
 */
final readonly class RoutingContext
{
    /**
     * The full set of origins the routing.source_intents origin CHECK and
     * routing.link_observations source CHECK accept. A new origin is a SCHEMA
     * change: both constraints AND this list, in the same commit. Validated at
     * construction because an unlisted origin used to survive all the way to
     * the intent INSERT and roll back the whole apply transaction (M-12, B6:
     * origin 'google_business_website' silently cost the Instagram connect the
     * router had already decided to apply).
     *
     * @var list<string>
     */
    public const ORIGINS = [
        'paste', 'website_import', 'link_in_bio', 'bio_harvest',
        'google_business', 'staff', 'reproject', 'commerce_probe',
    ];

    public function __construct(
        public ?User $user,
        /** Where the link came from — decides auto-apply vs suggest. */
        public string $origin = 'paste',
        /** Pre-account/staff builds have no user yet (plan §2, Decision 7). */
        public bool $preAccount = false,
        public ?string $importRunId = null,
    ) {
        if (! in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException(
                "Unknown routing origin '{$origin}' — the routing.* CHECK constraints would reject its ledger writes. Allowed: ".implode(', ', self::ORIGINS)
            );
        }
    }

    public static function forUser(User $user, string $origin = 'paste', ?string $importRunId = null): self
    {
        return new self($user, $origin, false, $importRunId);
    }

    public static function preAccountBuild(string $origin = 'staff', ?string $importRunId = null): self
    {
        return new self(null, $origin, true, $importRunId);
    }

    /**
     * A user pasting a link is asking for it directly; a harvester found it
     * incidentally. The distinction decides how much confidence we demand
     * before writing without asking.
     */
    public function isDirectRequest(): bool
    {
        return $this->origin === 'paste';
    }

    /**
     * A sign-up build harvesting its owner's links: a real, still-unclaimed
     * user on any indirect origin (decision 1, setup-dialog run). In this
     * context nothing auto-connects — every above-floor find becomes a
     * banded Choose the setup dialog asks about. A paste is excluded: the
     * dashboard preview's confirm flow is a direct request whoever pastes.
     */
    public function isSignupBuild(): bool
    {
        return $this->user?->isUnclaimed() === true && $this->origin !== 'paste';
    }

    /**
     * A build with a real person waiting at the other end — the only kind that
     * may spend money speculatively.
     *
     * This is deliberately NOT isSignupBuild(). That one is a SAFETY predicate:
     * PlacementPolicy and SourceReconciler use it to force suggest-don't-connect
     * for every unclaimed build, and narrowing it would let staff/ManyChat
     * outreach builds start auto-connecting — strictly worse. So the cost gate
     * gets its own predicate instead, and the two are allowed to disagree.
     *
     * The distinction matters because pre-scrape is billed: 15 of 32 ingest
     * connectors are CostClass::Actor (Apify). isSignupBuild() is true for ANY
     * unclaimed non-paste user, so an outreach build — which PreAccountBuild's
     * own comments say "may sit unclaimed for weeks with nobody to ask" — was
     * buying the same paid scrapes as someone seconds from the setup dialog.
     *
     * Deliberately reuses PreAccountBuild::isOutreach() rather than testing
     * built_via here. That predicate is keyed on WHO CREATED THE ROW
     * (built_by_staff_id) as well as built_via, precisely because the public
     * build endpoint sets built_via='signup' by default — a bare
     * `built_via === VIA_SIGNUP` test would be the exact mistake its docblock
     * warns about. It also fails SAFE (classifies as outreach when unsure),
     * which is the direction a spending gate wants.
     *
     * A missing build row therefore also means no spend.
     *
     * Costs one query per BUILD, not per link — the HasOne caches on the User
     * instance that the routing loop reuses.
     */
    public function isSelfServeSignup(): bool
    {
        if (! $this->isSignupBuild()) {
            return false;
        }

        $build = $this->user?->preAccountBuild;

        return $build !== null && ! $build->isOutreach();
    }
}
