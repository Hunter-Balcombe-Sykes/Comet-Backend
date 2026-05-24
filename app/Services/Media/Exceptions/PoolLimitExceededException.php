<?php

namespace App\Services\Media\Exceptions;

use DomainException;

/** Thrown when an upload would exceed the configured per-pool media cap. Controller maps to 422. */
class PoolLimitExceededException extends DomainException {}
