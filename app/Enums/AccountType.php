<?php

namespace App\Enums;

/**
 * Partna account type. Two user-selectable types:
 *
 *   - Partna   ('partna')   — the standard account; every pre-existing account.
 *   - Business ('business') — "Business Partna".
 *
 * Both behave identically today; account_type only records the user's choice
 * (set at signup, changeable in settings). Nothing branches on it yet.
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
