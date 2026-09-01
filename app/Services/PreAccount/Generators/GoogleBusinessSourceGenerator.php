<?php

namespace App\Services\PreAccount\Generators;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\PreAccount\SourcePrefetch;
use App\Services\Profile\BioSource;
use App\Services\Profile\ProfileEnricher;
use App\Support\BusinessName;
use Illuminate\Support\Facades\Log;

// Builds a provisional business user's site from a Google Business Profile
// place_id via the EXISTING Places + IdentitySync machinery: fetch details with
// the server key, persist a google-business connection (same shape as
// GoogleBusinessController::connect), fold identity into site.workplaces, and
// always dispatch the enrich job — it has its own free website-harvest
// fallback and only spends the paid Apify call when it decides it needs to.
class GoogleBusinessSourceGenerator implements SiteSourceGenerator
{
    public function __construct(
        private readonly GoogleBusinessService $service,
        private readonly ProfileEnricher $enricher,
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

    /**
     * Item 1a, phase one: Place Details + PII strip + the locality trim
     * (Item 1b), before any identity exists. The trimmed listing name seeds
     * the handle; the RAW name rides as the untrimmed ladder fallback so a
     * multi-location brand's second venue claims its suburb, not a digit.
     */
    public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
    {
        try {
            $details = $this->service->fetchPlaceDetails($sourceRef, $userId ?? 'pre-account');
        } catch (PlacesBudgetExhaustedException) {
            // RV-6: a budget stop is not a bad place_id — FAILURE_SCRAPE_FAILED
            // is the resettable failure state (build re-runs), never
            // sourceNotFound(), which would look like permanent bad data.
            throw SourceGenerationException::scrapeFailed('places budget exhausted');
        }
        if ($details === null) {
            // Covers both a bad/stale place_id and a missing server key — the
            // service is best-effort-null either way; the build must not hang.
            throw SourceGenerationException::sourceNotFound();
        }

        // PRIV-1: a pre-claim (provisional) build persists no third-party reviewer
        // PII — the visitor hasn't claimed the site and may never see this data
        // rendered, so we minimise what's stored rather than what's rendered
        // (render is gated by is_published, not claim status). GoogleBusinessFetch
        // mirrors this strip on refresh for as long as the owner stays unclaimed.
        $details = GoogleBusinessPayload::stripThirdPartyPii($details);

        $rawName = trim((string) ($details['name'] ?? '')) ?: trim((string) $sourceName);
        $trim = BusinessName::trimLocality($rawName, data_get($details, 'addressParts.suburb'));

        return new SourcePrefetch(
            payload: $details,
            displayName: $trim['name'] !== '' ? $trim['name'] : null,
            untrimmedName: $rawName !== '' && $rawName !== $trim['name'] ? $rawName : null,
            extra: ['trim_rule' => $trim['rule']],
        );
    }

    // $autoConnectBooking is handed to GoogleBusinessEnrichJob, which is where
    // this source's booking link is actually resolved (GoogleBusinessAutoSync
    // has its own seedBooking and never touches LinkRouter).
    public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void
    {
        if ($prefetch !== null) {
            $details = $prefetch->payload;
        } else {
            // Legacy/re-run path — fetch + strip exactly as before Item 1a.
            try {
                $details = $this->service->fetchPlaceDetails($sourceRef, (string) $user->id);
            } catch (PlacesBudgetExhaustedException) {
                throw SourceGenerationException::scrapeFailed('places budget exhausted');
            }
            if ($details === null) {
                throw SourceGenerationException::sourceNotFound();
            }
            $details = GoogleBusinessPayload::stripThirdPartyPii($details);
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

        // Always dispatch the enrich job, token or not — it has its own free
        // website-harvest fallback (WebsiteLinkHarvester) and only spends the
        // paid Apify call via needsApify(), gracefully no-op'ing the Apify leg
        // when no token is configured (GoogleBusinessApifyScraper::fetch()).
        // Gating the DISPATCH itself on the token, as before, skipped that free
        // path entirely and silently dropped previous-website/social detection
        // whenever Apify wasn't configured.
        $connection->forceFill([
            'place_id' => $sourceRef,
            'apify_status' => 'pending',
        ])->saveQuietly();

        // Identity fold happens automatically here: IntegrationConnectionObserver::saved()
        // sees this google-business row created/payload-changed and calls
        // IdentitySync::applyFromGooglePayload for us (business accounts get full
        // overwrite via AccountCapabilities::google_business_full_sync) — same engine
        // as connect. Do NOT call IdentitySync directly, or the fold runs twice.

        // Business accounts adopt the Google name as display name (capability-gated),
        // mirroring GoogleBusinessController::maybeAdoptGoogleName EXACTLY: word-trim
        // to the 80-char sanity bound before writing, and only write when it
        // actually changed. (UpdateUserRequest caps display_name at 255 for both
        // account types — the bound here exists to match the controller, not
        // to satisfy validation.)
        if ($name !== '' && AccountCapabilities::for($user)->google_business_sets_display_name) {
            // Item 1b: the listing's own suburb comes off the end first (the
            // multi-location disambiguator stays available to the handle
            // ladder); then the 80-char word-trim as before.
            $localityTrim = BusinessName::trimLocality($name, data_get($details, 'addressParts.suburb'));
            if ($localityTrim['rule'] !== null) {
                Log::info('name_trim', [
                    'user_id' => $user->id,
                    'from' => $name,
                    'to' => $localityTrim['name'],
                    'rule' => $localityTrim['rule'],
                ]);
            }
            $trimmedName = BusinessName::wordTrim($localityTrim['name']);
            if ($user->display_name !== $trimmedName) {
                $user->display_name = $trimmedName;
                $user->save();
            }
        }

        // The shared enrichment step (ProfileEnricher), the same one the Instagram
        // source runs — an About and public contact gated to the listing's own
        // words. Runs AFTER the connection write so Google's structured phone has
        // already folded onto the user (IntegrationConnectionObserver::saved ->
        // IdentitySync), and fill-if-empty then leaves it alone.
        //
        // Names are NOT taken from the model here: maybeAdoptGoogleName above owns
        // display_name for a business account.
        //
        // KNOWN, DEFERRED (2026-08-28): this rarely changes what a business account
        // ends up with. 74% of real listings carry no description at all, so there
        // is nothing to analyse; for the rest, GoogleBusinessAutoSync (queued, so it
        // lands after this returns) seeds site.workplaces.description from the same
        // editorialSummary and WorkplaceObserver mirrors description -> users.bio,
        // overwriting the gated About with Google's raw sentence. Sourcing a real
        // bio input for this lane — the business's OWN website text, present on 79%
        // of listings — and settling that precedence against the mirror is separate
        // work. The seam is wired now so that work is a change of input, not of
        // structure.
        $this->enricher->enrich($user, new BioSource(
            handle: (string) ($user->handle ?: $sourceRef),
            fullName: $name ?: null,
            biography: is_string($details['editorialSummary'] ?? null) ? $details['editorialSummary'] : null,
            businessCategory: is_string($details['category'] ?? null) ? $details['category'] : null,
        ));

        GoogleBusinessEnrichJob::dispatch((string) $user->id, $sourceRef, $autoConnectBooking);
    }
}
