<?php

namespace App\DTOs\Moderation;

final class CloudflareCsamMatchDto
{
    public function __construct(
        public readonly string $matchId,
        public readonly string $r2Key,
        public readonly string $matchedHash,
        public readonly string $confidence,
        public readonly string $matchedAgainst,
        public readonly array $rawPayload,
    ) {}

    public static function fromArray(array $a): self
    {
        // Guard required fields — missing keys throw TypeError which would produce
        // an unhandled 500. Throw InvalidArgumentException so the controller can
        // return a clean 422 and Cloudflare can re-send after a schema fix.
        $required = ['match_id', 'r2_key', 'matched_hash', 'confidence'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $a)) {
                throw new \InvalidArgumentException("CSAM webhook payload missing required field: {$key}");
            }
        }

        return new self(
            matchId:        $a['match_id'],
            r2Key:          $a['r2_key'],
            matchedHash:    $a['matched_hash'],
            confidence:     $a['confidence'],
            matchedAgainst: $a['matched_against'] ?? 'unknown',
            rawPayload:     $a,
        );
    }
}
