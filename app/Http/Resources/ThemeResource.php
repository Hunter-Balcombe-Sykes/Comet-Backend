<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: API resource for site.themes rows. Themes are admin-managed catalogue
// entries — no timestamps in the response shape, no per-professional fields.
class ThemeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'config' => (object) ($this->config ?? []),
            'is_default' => $this->is_default,
        ];
    }
}
