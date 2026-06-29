<?php

namespace App\Services\Http;

use RuntimeException;

/** Raised when a URL is unsafe to fetch (SSRF guard) or unfetchable. */
class SafeUrlException extends RuntimeException {}
