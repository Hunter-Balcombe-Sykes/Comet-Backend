<?php

namespace App\Enums;

/**
 * Partna account type. Two user-selectable types:
 *
 *   - Partna   ('partna')   — the standard account; every pre-existing account.
 *   - Business ('business') — "Business Partna".
 *
 * Both behave identically except where AccountCapabilities says otherwise.
 * Never branch on the type directly outside AccountCapabilities.
 *
 * Internal Partna staff are NOT an account type — staff identity + powers live
 * solely in core.partna_staff (role support/admin), gated by the `staff`
 * middleware and the staff Policies. A staff member may separately hold a
 * normal Partna/Business account; the two facts are independent.
 *
 * These two values are the whole domain: core.users.account_type is constrained
 * by users_account_type_check, and the test schema mirrors that CHECK so an
 * invalid seed fails at the insert.
 */
enum AccountType: string
{
    case Partna = 'partna';
    case Business = 'business';
}
