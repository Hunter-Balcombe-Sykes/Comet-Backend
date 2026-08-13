<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use App\Services\Content\FreshaServiceItems;
use Illuminate\Http\Request;

/**
 * Fresha saved selection: the connected store url + name, the chosen team
 * member ("you"), the service menu, and the curated hidden-service ids.
 * `employee` is a scraped object passed through verbatim (its inner keys
 * come straight from Fresha's __NEXT_DATA__ / booking GraphQL —
 * re-allowlisting it would risk dropping fields). This Resource allowlists
 * only the top-level selection keys.
 *
 * `$this->resource` is the inner selection ARRAY (the controller already
 * unwrapped the stored `{url, selection}` envelope before wrapping).
 *
 * Slice 3b Task 12: `services[]` no longer comes from `$this->resource` —
 * it is read live from content.* (FreshaServiceItems), the same wire shape
 * reproduced from the pool instead of the stored blob. `url`, `storeName`,
 * `mode`, `employee` and `hiddenServiceIds` are unchanged: they are the
 * owner's own choices, not scraped content, and this slice does not move
 * them. `services[]` deliberately does NOT filter hidden rows — this
 * Resource passes them through verbatim and `hiddenServiceIds` is the
 * sibling key that expresses the hiding; the dashboard's un-hide affordance
 * needs the hidden rows present to render them.
 *
 * Fix round 1 (Finding 1): the user whose services[] this renders is an
 * EXPLICIT constructor argument, not read off the ambient request. An
 * earlier version read `$request->attributes->get('professional')` inside
 * toArray() on the reasoning that every call site is a FreshaController
 * action, where that attribute is always the same person whose selection is
 * being rendered. That reasoning holds for FreshaController today but is not
 * a structural guarantee: this class is also registered as this platform's
 * `resource()` on its registry descriptor (PlatformRegistryServiceProvider),
 * and GenericPlatformController::shape() — the registry-driven render path —
 * instantiates a descriptor's resource class with NO user parameter at all
 * (unlike its sibling accountsList(), which takes one explicitly). Fresha's
 * descriptor has no `->routes(...)` call, so `routeShape()` defaults to
 * `Bespoke` and that path is unreachable today (PlatformRouteShape::Bespoke
 * short-circuits the registry-driven route loop in routes/api/platforms.php)
 * — but a resource that only renders the right person's data by coincidence
 * of which routes happen to be wired is one route-shape flip away from
 * silently rendering the VIEWER's menu in place of the OWNER's, on a surface
 * whose entire purpose is displaying that owner's prices. Threading the id
 * explicitly makes that impossible by construction instead of by accident.
 */
class FreshaSelectionResource extends ApiResource
{
    /**
     * @param  mixed  $resource  the inner selection array
     * @param  string|null  $userId  whose services[] to render from content.*.
     *                               Null is a DELIBERATE choice, not an oversight —
     *                               it's what a caller with genuinely no user to
     *                               give (GenericPlatformController::shape(), which
     *                               only ever passes $resource) gets by simply
     *                               omitting the second argument, and it engages the
     *                               stored-blob fallback in services() below.
     */
    public function __construct($resource, private readonly ?string $userId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'storeName' => $this->resource['storeName'] ?? null,
            // 'employee' | 'storewide' — storewide (Business accounts) has employee = null.
            'mode' => $this->resource['mode'] ?? 'employee',
            'employee' => $this->resource['employee'] ?? null,
            'services' => $this->services(),
            'hiddenServiceIds' => $this->resource['hiddenServiceIds'] ?? [],
        ];
    }

    /**
     * Fix round 1 (Finding 2): a null $userId falls back to the stored
     * blob's own `services` key (the pre-3b behaviour) — but that fallback
     * is a fail-open on a price surface: spec §1.4 measured the blob and
     * content.* disagreeing on price for 22 of 23 services on one salon
     * (understated) and by half on another ($360 vs $180 storewide), and
     * that divergence is the entire reason this slice exists. A caller that
     * reaches this branch without meaning to (a future route-shape flip —
     * see the class docblock) would silently start serving stale/wrong
     * prices with no signal anywhere. report() makes that observable
     * without making it fatal: the deliberate no-user case
     * (GenericPlatformController::shape(), unreachable for Fresha today)
     * still renders successfully, it just now pages on-call if it ever
     * actually fires.
     *
     * @return list<array<string, mixed>>
     */
    private function services(): array
    {
        if ($this->userId === null) {
            report(new \RuntimeException(
                'FreshaSelectionResource rendered services[] from the stored blob — no user id was supplied. '.
                'Expected only from a deliberate no-user caller (GenericPlatformController::shape()); if this '.
                'fired from an authenticated Fresha endpoint, the blob and content.* have silently diverged on '.
                'a price surface (spec §1.4).',
            ));

            return $this->resource['services'] ?? [];
        }

        return app(FreshaServiceItems::class)->selectionServices($this->userId);
    }
}
