<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/** Thrown when the video processing job cannot be dispatched. Controller maps to 503. */
class VideoDispatchFailedException extends RuntimeException {}
