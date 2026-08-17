<?php

namespace App\Http\Resources\Routing;

use App\Services\Platforms\ConnectionDisplayName;

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
            // The name of the connected THING, not the platform — displayName is
            // the platform's label. Without it a per-connection row falls back to
            // a hostname. Guarded on is_string() for the same reason as url: the
            // payload column is jsonb and nothing constrains its shape.
            'name' => is_string($this->payload['name'] ?? null) ? $this->payload['name'] : null,
            // The ONE human name for the row (R9): display_name → the connected
            // thing's name → native handle with its prefix → a handle captured
            // from the url by the surface's own detector. Null means "no idea,
            // label the url" — never the brand label.
            'accountName' => ConnectionDisplayName::for((string) $this->surface_key, (array) ($this->payload ?? [])),
            // The connected thing's own picture where the payload has one — a
            // channel avatar, a store logo, an enriched card's og image or
            // favicon — so the connect summary and the table can show WHAT was
            // connected, not only which platform (R9 / connect-summary).
            'avatarUrl' => ConnectionDisplayName::avatarFor((array) ($this->payload ?? [])),
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
