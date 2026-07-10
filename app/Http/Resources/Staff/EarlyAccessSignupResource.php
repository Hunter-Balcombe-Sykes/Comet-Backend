<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;

// OV-A: staff-facing early-access row. Deliberately exposes email (hidden on
// the model) — the staff early-access screens exist to manage these people.
// The invite token hash NEVER leaves the server.
class EarlyAccessSignupResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->getAttribute('email'),
            'type' => $this->type,
            'workplace_or_industry' => $this->workplace_or_industry,
            'platforms' => $this->platforms ?? [],
            'status' => $this->status,
            'source' => $this->source,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invite_meta' => $this->invite_meta,
            'signed_up_at' => $this->signed_up_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
