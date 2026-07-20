<?php

namespace App\Services\Media\Exceptions;

use DomainException;

/**
 * Thrown when a concurrent request already replaced this (site, purpose)
 * design singleton before this request's INSERT could land — the DB's
 * partial unique index (site_media_design_singleton_purpose_uq) rejects the
 * second row outright. Nothing was written to storage for the loser
 * (storeOriginal() only runs after a successful insert), so there is no
 * orphaned file to clean up — the winner's row/file stand as-is. Controller
 * maps to 409; the client can safely resubmit.
 */
class SingletonConflictException extends DomainException {}
