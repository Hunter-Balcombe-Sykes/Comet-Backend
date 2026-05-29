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
