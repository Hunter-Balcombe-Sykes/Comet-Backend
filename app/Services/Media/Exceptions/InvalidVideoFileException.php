<?php

namespace App\Services\Media\Exceptions;

use DomainException;

/** Thrown when ffprobe rejects the uploaded container. Controller maps to 422. */
class InvalidVideoFileException extends DomainException {}
