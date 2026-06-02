<?php

namespace App\Http\Resources\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a moderation evidence record.
 * The payload JSONB is included — it contains captured content snapshots,
 * not reporter PII, so it is safe to expose to staff reviewers.
 */
class EvidenceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'evidence_type' => $this->evidence_type,
            'payload' => $this->payload,
            'content_hash' => $this->content_hash,
            'captured_at' => $this->captured_at?->toIso8601String(),
        ];
    }
}
