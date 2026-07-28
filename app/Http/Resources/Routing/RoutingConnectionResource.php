<?php

namespace App\Http\Resources\Routing;

use App\Catalog\CatalogNotCompiled;
use App\Catalog\CompiledCatalog;
use App\Http\Resources\ApiResource;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Http\Request;

/**
 * One connection row for the routing dashboard (GET /routing/connections and
 * the set-primary response). Carries `routingClass` + `isPrimary` so the
 * SetPrimarySheet can group by class and mark the current CTA without a
 * second request.
 *
 * @mixin IntegrationConnection
 */
class RoutingConnectionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $surface = $this->surfaceData();

        return [
            'id' => (string) $this->id,
            'surfaceKey' => $this->surface_key,
            'platform' => $this->platform,
            'displayName' => $surface['display_name'] ?? $this->surface_key,
            'brandKey' => $surface['brand_key'] ?? null,
            'routingClass' => $this->routing_class,
            'isPrimary' => (bool) $this->is_primary,
            'resourceId' => $this->resource_id,
            'url' => is_string($this->payload['url'] ?? null) ? $this->payload['url'] : null,
            'isActive' => (bool) $this->is_active,
        ];
    }

    /** @return array<string, mixed>|null */
    private function surfaceData(): ?array
    {
        try {
            return CompiledCatalog::surface((string) $this->surface_key);
        } catch (CatalogNotCompiled) {
            return null;
        }
    }
}
