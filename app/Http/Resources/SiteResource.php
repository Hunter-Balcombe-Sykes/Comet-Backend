<?php

namespace App\Http\Resources;

use App\Models\Core\Site\Site;
use App\Services\Design\DesignRationaleService;
use Illuminate\Http\Request;

// API resource for site.sites rows (dashboard + staff side).
// `settings` is passed through unchanged — the dashboard reads non-design
// settings (booking, GBP, etc). Per-user design vars live in site.design_kits
// (separate table), exposed via the skeleton-system payload.
class SiteResource extends ApiResource
{
    /**
     * Include the design transparency line (spec §3). OFF by default: it costs two
     * extra DB reads (contribution ledger + manual kit), so only the design-editor
     * round-trips (site show/update) opt in via withRationale() — staff/self/
     * visibility responses that never render the line skip the cost.
     */
    private bool $withRationale = false;

    /** Opt this resource into emitting design_rationale. Fluent, for the design GET/PATCH. */
    public function withRationale(bool $with = true): static
    {
        $this->withRationale = $with;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        // FOUND-16: the 5 promoted columns are the source of truth. Re-merge
        // the non-null ones into the settings object so the response shape is
        // identical before/after the JSONB strip. !== null keeps booleans like
        // show_branding=false while omitting unset keys (absent, not null).
        $promoted = array_filter([
            'show_branding' => $this->show_branding,
            'charlie_enabled' => $this->charlie_enabled,
            'services_auto_sync_enabled' => $this->services_auto_sync_enabled,
            'booking_mode' => $this->booking_mode,
            'manual_booking_url' => $this->manual_booking_url,
        ], static fn ($value): bool => $value !== null);

        $settings = array_merge($settings, $promoted);

        return array_merge([
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'subdomain' => $this->subdomain,
            'skeleton_id' => $this->skeleton_id,
            'is_published' => $this->is_published,
            'subdomain_changed_at' => $this->subdomain_changed_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),
            'settings' => (object) $settings,
            // Stored design-kit vars (non-null partial from site.design_kits).
            // The /account/design editor reads this to show saved choices on
            // reload — until now its only read surface was same-session cache
            // seeding after saves. (object) so an empty kit serialises as {}.
            'design_kit' => (object) $this->resource->designKitVars(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ],
            // Transparency line (spec §3): plain-language WHY the design looks the
            // way it does, from the contribution ledger + manual overrides. Never
            // exposes raw column names or sensitive detail. Opt-in (withRationale)
            // — only the design-editor round-trips pay its two DB reads; the
            // dashboard renders the summary + an expandable per-area list.
            $this->withRationale
                ? ['design_rationale' => app(DesignRationaleService::class)->forSite((string) $this->id)]
                : [],
            // Booking settings surfaced at the top level for the dashboard's
            // booking editor — mirrors the dedicated updateBookingSettings endpoint.
            // Conditionally merged so the keys are absent (not null) when unset,
            // keeping the shape clean for clients that don't care about booking.
            array_key_exists('booking_mode', $settings) ? ['booking_mode' => $settings['booking_mode']] : [],
            array_key_exists('manual_booking_url', $settings) ? ['manual_booking_url' => $settings['manual_booking_url']] : []);
    }
}
