<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Instagram saved selection (manual + automatic modes share this shape). The
 * stored payload also carries an internal `_folder` (the R2 prefix the
 * disconnect-cleanup observer uses, CONS-21). It is INTENTIONALLY NOT in this
 * allowlist — the dashboard never renders it, and the public endpoint already
 * strips it (PublicIntegrationConnectionResource). The stored row keeps
 * `_folder`; we just stop emitting it (the one documented deviation in CONS-38).
 *
 * `$this->resource` is the selection ARRAY. Nested `images[]` (R2 urls) pass
 * through verbatim.
 */
class InstagramConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->resource['username'] ?? null,
            'fullName' => $this->resource['fullName'] ?? null,
            'profilePicUrl' => $this->resource['profilePicUrl'] ?? null,
            'businessCategory' => $this->resource['businessCategory'] ?? null,
            'followersCount' => $this->resource['followersCount'] ?? null,
            'postsCount' => $this->resource['postsCount'] ?? null,
            'mode' => $this->resource['mode'] ?? null,
            'images' => $this->resource['images'] ?? [],
            'imagesDropped' => $this->resource['imagesDropped'] ?? 0,
            // `_folder` intentionally omitted — see class docblock.
        ];
    }
}
