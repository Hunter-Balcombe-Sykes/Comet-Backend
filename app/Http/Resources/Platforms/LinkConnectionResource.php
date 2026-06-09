<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Link-only platforms (Facebook + TikTok): the saved selection is just a
 * username and its canonical profile url — identical shape, so one Resource
 * serves both. `$this->resource` is the selection ARRAY the controller built
 * or read back (not an Eloquent model), so fields are read via array offset.
 *
 * This is the authenticated DASHBOARD contract — the bare selection payload,
 * not the public model envelope ({resourceId,payload,lastRefreshedAt}).
 */
class LinkConnectionResource extends ApiResource
{
    /**
     * @return array{username: string|null, url: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->resource['username'] ?? null,
            'url' => $this->resource['url'] ?? null,
        ];
    }
}
