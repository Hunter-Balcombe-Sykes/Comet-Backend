<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/** Thrown when the original file fails to land on the media disk. Controller maps to 500. */
class OriginalStoreFailedException extends RuntimeException {}
