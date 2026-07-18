<?php

namespace App\Services\Segments\Criteria;

/**
 * The segment criterion registry.
 *
 * Explicit and ordered on purpose — no auto-discovery, so `grep` finds every
 * criterion the engine will ever apply. Adding a criterion is one class plus
 * one line here. Order affects only SQL clause order, never results.
 */
final class SegmentCriteria
{
    /** @return list<SegmentCriterion> */
    public static function all(): array
    {
        return [
            new AccountTypeCriterion,
            new SectorCriterion,
            new CreatedRangeCriterion,
            new HasIntegrationCriterion,
            new EarlyAccessCriterion,
            new CountryCodeCriterion,
            new LocationStateCriterion,
            new LocationCityCriterion,
            new TenureCriterion,
            new IgFollowersCriterion,
            new AnalyticsCriterion,
        ];
    }
}
