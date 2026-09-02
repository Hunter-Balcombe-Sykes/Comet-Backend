<?php

namespace App\Routing;

use App\Ingest\SourceProvisioner;
use App\Jobs\Platforms\FreshaListingCandidatesJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\PreAccount\BuildProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pre-scrape lane (A.4, decision 5): a sign-up build's AUTO-band
 * suggestion starts syncing invisibly the moment it is proposed, so the
 * setup dialog can offer real items rather than a promise. The connection
 * is born hidden (A.3) — nothing public changes until the person accepts.
 *
 * Best-effort by design: any failure here leaves the proposed intent
 * exactly as the inbox would have shown it anyway.
 */
class PreScrapeDispatcher
{
    public function __construct(private readonly SuggestionApplier $applier) {}

    /**
     * @param  array<string, mixed>  $surface  compiled catalog surface data
     */
    public function maybeApply(User $user, Placement $placement, array $surface, string $intentId): void
    {
        if (! (bool) config('partna.pre_account.pre_scrape_enabled', true)) {
            return;
        }

        $surfaceKey = (string) $placement->surfaceKey;

        // Fresha's venue page is readable without connecting anything, so it
        // proposes listing candidates at ANY band (decision 5) — once, and
        // only while no Google Business connection or candidate exists.
        if ($surfaceKey === 'fresha.book') {
            $this->maybeProposeFreshaListing($user, $intentId);
        }

        if ($placement->band !== 'auto') {
            return;
        }

        if (SourceProvisioner::sourceKeyFor($surfaceKey) === null) {
            return;
        }

        // apply()'s own capability re-check would flip the intent to
        // blocked/'gate' AND throw — right for a person accepting, wrong for
        // a background lane. Denied classes just stay ordinary suggestions.
        if (RoutingCapabilityGate::denialFor($user, (string) $surface['routing_class']) !== null) {
            return;
        }

        // A live row for this account — hidden or visible — means the scrape
        // side is already owned; matchExisting() can't answer this (it skips
        // hidden rows by design), so ask by exact resource_id.
        $exists = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('surface_key', $surfaceKey)
            ->where('resource_id', (string) $placement->identifier)
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            return;
        }

        $intent = DB::table('routing.source_intents')
            ->where('id', $intentId)
            ->where('user_id', $user->id)
            ->first();
        if ($intent === null || (string) $intent->state !== 'proposed') {
            return;
        }

        $label = (string) ($surface['display_name'] ?? $surfaceKey);
        BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Syncing '.$label);

        try {
            $this->applier->apply($user, $intent, $surface, hidden: true);
            BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_LANDED, $label.' synced');
        } catch (\Throwable $e) {
            report($e);
            Log::warning('routing.pre_scrape.apply_failed', [
                'user_id' => (string) $user->id,
                'surface_key' => $surfaceKey,
                'error' => $e->getMessage(),
            ]);
            BuildProgress::noteForUser((string) $user->id, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_FAILED, $label.' could not sync');
        }
    }

    private function maybeProposeFreshaListing(User $user, string $intentId): void
    {
        if ($user->integrationConnections()->where('platform', 'google-business')->exists()) {
            return;
        }
        if (DB::table('site.workplace_candidates')->where('user_id', $user->id)->exists()) {
            return;
        }

        $url = DB::table('routing.source_intents')
            ->where('id', $intentId)
            ->where('user_id', $user->id)
            ->value('canonical_url');
        if (! is_string($url) || $url === '') {
            return;
        }

        FreshaListingCandidatesJob::dispatch((string) $user->id, $url);
    }
}
