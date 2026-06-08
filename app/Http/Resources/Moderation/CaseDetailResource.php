<?php

namespace App\Http\Resources\Moderation;

use App\Http\Resources\ApiResource;

/**
 * Detail-view serialization of a moderation case — includes nested relations.
 *
 * Uses whenLoaded() throughout so callers control which relations are hydrated;
 * un-loaded relations are omitted from the response rather than triggering N+1
 * queries. Composes CaseSignalResource which enforces the PII strip on signals.
 */
class CaseDetailResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'case' => (new CaseResource($this->resource))->toArray($request),
            'signals' => CaseSignalResource::collection($this->whenLoaded('signals')),
            'evidence' => EvidenceResource::collection($this->whenLoaded('evidence')),
            'decisions' => DecisionResource::collection($this->whenLoaded('decisions')),
        ];
    }
}
