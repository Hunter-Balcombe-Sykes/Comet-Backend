<?php

namespace App\Exceptions\Routing;

use RuntimeException;

// Reported when a compiled detector carries a regex preg_match() refuses to
// compile (SLOP-21). The runtime verdict is deliberately unchanged — the
// projector still fails that detector closed, byte-identically to the swallowed
// `@preg_match` that came before — because throwing here would 500 the
// debounced paste preview (LinkRoutingService::preview) on every paste of an
// affected URL. What was missing was the SIGNAL: a `false` return is
// indistinguishable from "this URL simply doesn't match", so a typo'd pattern
// takes a whole surface dark silently.
//
// The real gate is `catalog:compile`, which now refuses to write an artefact
// containing an uncompilable pattern. This exception covers what bypasses that
// gate: a hand-edited artefact, an artefact built by an older binary, or a PCRE
// that compiles on the build host and not on the runtime one (a JIT/backtrack
// limit, a differing PCRE2 build).
class MalformedDetectorPatternException extends RuntimeException
{
    public function __construct(public string $detectorId, public string $field, public string $pattern)
    {
        parent::__construct("Detector {$detectorId} carries a malformed {$field}: {$pattern}");
    }
}
