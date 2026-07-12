<?php

namespace App\Enums;

/**
 * Partna account type. Two user-selectable types:
 *
 *   - Partna   ('partna')   — the standard account; every pre-existing account.
 *   - Business ('business') — "Business Partna".
 *
 * Both behave identically except where AccountCapabilities says otherwise.
 *
 * Internal Partna staff are NOT an account type — staff identity + powers live
 * solely in core.partna_staff (role support/admin), gated by the `staff`
 * middleware and the staff Policies. A staff member may separately hold a
 * normal Partna/Business account; the two facts are independent.
 *
 * Individual ('individual') is a legacy value kept ONLY so Eloquent casting never
 * throws on a row read between the code deploy and the backfill migration
 * (20260612120000_account_type_partna_business). It is not user-selectable —
 * request validation rejects it.
 */
enum AccountType: string
{
    case Partna = 'partna';
    case Business = 'business';

    case Individual = 'individual';
}
