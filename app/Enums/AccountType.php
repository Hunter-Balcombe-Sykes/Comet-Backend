<?php

namespace App\Enums;

/**
 * Partna account type — individual-only stub.
 *
 * The brand and partner cases have been removed as part of the standalone-pages
 * strip. All accounts are individual. The enum is kept (not deleted) because
 * Professional casts the account_type column to it and resources emit it.
 */
enum AccountType: string
{
    case Individual = 'individual';
}
