<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for core.themes rows. Themes are admin-managed catalogue
// entries — no timestamps in the response shape, no per-professional fields.
class ThemeResource extends ApiResource
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
