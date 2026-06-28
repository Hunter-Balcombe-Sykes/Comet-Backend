<?php

namespace App\Services\Platforms\Strategies\Fetch;

// A stored payload is missing a key the fetch needs (link, artistId, handle, …).
// Mirrors PlatformRefresher's status='error' bucket — a data-integrity problem the
// Plan-6 refresher logs loudly (integrations.refresh.bad_shape). Distinct from a
// transient upstream miss (FetchUnavailableException).
class FetchShapeException extends \RuntimeException {}
