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
 * Staff ('staff') is the internal Partna staff account (migration
 * 20260711000000): NO site, NO integrations; granular staff powers derive in
 * AccountCapabilities from the linked core.partna_staff role. Never
 * user-selectable — signup validation rejects it; rows are created by staff
 * tooling/tinker only.
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
    case Staff = 'staff';

    case Individual = 'individual';
}
