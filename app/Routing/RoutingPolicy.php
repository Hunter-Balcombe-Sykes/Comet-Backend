<?php

namespace App\Routing;

/**
 * What remains of the routing policy after the confidence system was deleted
 * (2026-09-03): one rule, about one class.
 *
 * This class used to hold a per-routing-class threshold table — auto 70–80,
 * suggest 45–55, a 10-point penalty for harvested links, a 10-point minimum
 * margin, a 15-point discount for the signup dialog — and PlacementPolicy
 * compared the projector's score against it to decide whether a link could be
 * written. All of it is gone, and none of it is coming back under another name.
 *
 * The numbers were never measurable. Their own docblock said they would be
 * "retuned from real traffic via detector_observations", and they never were,
 * because there is no observation that tells you whether 55 should have been
 * 52 — a wrong write and a missed write both look like one row. What they
 * actually encoded was a proxy for a structural question, badly: "is this URL
 * specific enough to name an account?" That question is now asked directly, of
 * the catalog, by LinkValidity, and answered yes or no rather than 59.
 *
 * The one rule left is not a threshold and never was.
 */
class RoutingPolicy
{
    /** The Ignore class never writes — no rule, no score, no exceptions. */
    public static function isIgnored(string $routingClass): bool
    {
        return $routingClass === 'ignore';
    }
}
