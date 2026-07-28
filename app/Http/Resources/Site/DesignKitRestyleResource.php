<?php

namespace App\Http\Resources\Site;

use App\Models\Core\Site\DesignKitRestyle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DesignKitRestyle
 */
class DesignKitRestyleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appliedAt' => $this->applied_at?->toIso8601String(),
            'undoneAt' => $this->undone_at?->toIso8601String(),
        ];
    }
}
