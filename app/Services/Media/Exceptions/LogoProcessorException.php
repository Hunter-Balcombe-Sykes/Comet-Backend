<?php

namespace App\Services\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by LogoProcessorClient on misconfiguration, transport failure, or a
 * malformed response from the self-hosted logo-processor container. Treated as
 * transient by ProcessLogoVariantsJob (retried, then falls back to the standard
 * WebP pipeline).
 */
class LogoProcessorException extends RuntimeException {}
