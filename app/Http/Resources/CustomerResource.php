<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for customer records — transforms email, phone, name, source, notes, and marketing opt-in status.
class CustomerResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'full_name' => $this->full_name,
            'source' => $this->source,
            'notes' => $this->notes,
            'marketing_opt_in_cached' => $this->marketing_opt_in_cached,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
