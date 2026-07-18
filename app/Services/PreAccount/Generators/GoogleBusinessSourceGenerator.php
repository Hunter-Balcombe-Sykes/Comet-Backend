<?php

namespace App\Services\PreAccount\Generators;

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use App\Support\BusinessName;

// Builds a provisional business user's site from a Google Business Profile
// place_id via the EXISTING Places + IdentitySync machinery: fetch details with
// the server key, persist a google-business connection (same shape as
// GoogleBusinessController::connect), fold identity into site.workplaces, and
// kick the Apify enrichment job when a token is configured.
class GoogleBusinessSourceGenerator implements SiteSourceGenerator
{
    public function __construct(
        private readonly GoogleBusinessService $service,
    ) {}

    public function normalizeRef(string $raw): string
    {
        $ref = trim($raw);
        if ($ref === '' || mb_strlen($ref) > 300) {
            throw new \InvalidArgumentException('That does not look like a Google place id.');
        }

        return $ref;
    }

    public function dedupeKey(string $normalizedRef): string
    {
        return $normalizedRef; // place_ids are case-sensitive — never case-fold them
    }

    public function handleSeed(string $normalizedRef, ?string $sourceName): string
    {
        // A place_id is opaque; the business name (F1, required at validation)
        // seeds the handle/subdomain.
        return $sourceName ?: 'business';
    }

    public function generate(User $user, Site $site, string $sourceRef): void
    {
        $details = $this->service->fetchPlaceDetails($sourceRef);
        if ($details === null) {
            // Covers both a bad/stale place_id and a missing server key — the
            // service is best-effort-null either way; the build must not hang.
            throw SourceGenerationException::sourceNotFound();
        }

        $name = trim((string) ($details['name'] ?? '')) ?: $user->display_name;

        $payload = [
            'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($name).'&query_place_id='.rawurlencode($sourceRef),
            'placeId' => $sourceRef,
            'name' => $name,
            ...$details,
        ];

        // Same row shape GoogleBusinessController::connect persists. resource_id
        // matches ManagesIntegrationConnection::defaultResourceId() (= platform()),
        // which GoogleBusinessController doesn't override: Platform::GoogleBusiness->value.
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => Platform::GoogleBusiness->value,
                'resource_id' => Platform::GoogleBusiness->value,
            ],
            [
                'payload' => $payload,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        $enrich = (bool) config('services.apify.token');
        $connection->forceFill([
            'place_id' => $sourceRef,
            'apify_status' => $enrich ? 'pending' : null,
        ])->saveQuietly();

        // Identity fold happens automatically here: IntegrationConnectionObserver::saved()
        // sees this google-business row created/payload-changed and calls
        // IdentitySync::applyFromGooglePayload for us (business accounts get full
        // overwrite via AccountCapabilities::google_business_full_sync) — same engine
        // as connect. Do NOT call IdentitySync directly, or the fold runs twice.

        // Business accounts adopt the Google name as display name (capability-gated),
        // mirroring GoogleBusinessController::maybeAdoptGoogleName EXACTLY: word-trim
        // to the business cap before writing, and only write when it actually changed.
        // An untrimmed write 422s the user's first profile edit after claim
        // (UpdateUserRequest enforces max:15 for business accounts).
        if ($name !== '' && AccountCapabilities::for($user)->google_business_sets_display_name) {
            $trimmedName = BusinessName::wordTrim($name);
            if ($user->display_name !== $trimmedName) {
                $user->display_name = $trimmedName;
                $user->save();
            }
        }

        if ($enrich) {
            GoogleBusinessEnrichJob::dispatch((string) $user->id, $sourceRef);
        }
    }
}
