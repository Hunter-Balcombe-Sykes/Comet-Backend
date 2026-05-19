<?php

namespace App\Enums;

/**
 * Source of truth for the three Partna account types.
 *
 * - brand:      Shopify-connected commerce operator. Terminal — there is no
 *               promotion path TO brand and no demotion path FROM brand.
 *               Only `BootstrapController` writes `account_type='brand'`.
 * - partner:    Professional affiliated with a brand; sells on the brand's
 *               storefront via BrandPartnerLink.
 * - individual: Professional with a public profile sitepage; no commerce.
 *
 * Values are enforced at the DB level via `professionals_account_type_check`.
 *
 * @see supabase/migrations/20260520000100_add_account_type_constraints_and_trigger.sql
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §8, §50 (non-negotiable rules #1, #5, #12)
 */
enum AccountType: string
{
    case Brand = 'brand';
    case Partner = 'partner';
    case Individual = 'individual';
}
