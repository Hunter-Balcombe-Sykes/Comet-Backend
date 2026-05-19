<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when `AccountTypeTransitionService::transition()` is called with a
 * forbidden from/to combination. All transitions to or from `brand` are
 * rejected — brand is set at signup only and is terminal.
 *
 * @see App\Services\Accounts\AccountTypeTransitionService
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §50 rule #12
 */
class InvalidAccountTypeTransition extends DomainException {}
