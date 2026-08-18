<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The gate every AUTOMATIC previous_website write passes through (owner,
 * 2026-08-19).
 *
 * The incident: a Google Business listing's "website" was the venue's Fresha
 * booking page. It was archived as the workplace's previous website, the
 * website scan ran on it, and Fresha's favicon became the site's square mark.
 * A URL on any platform we know is never a previous website — it is a
 * platform candidate, and belongs to the routing pipeline (which places it as
 * a connection, holds it for review, or notes it as a link).
 *
 * `isPlatformUrl` answers with the catalogue's surface key (or the legacy
 * classifier's platform) when the URL is one of ours; `divert` routes it
 * through LinkRoutingService for the user, best-effort. Manual writes (the
 * Settings card, the workplace upsert) are NOT gated — a person typing a
 * URL into "previous website" is stating a fact, not making a claim we
 * should second-guess; the design-evidence scan is what the incident
 * actually damaged and that path is capability-gated separately.
 */
final class PreviousWebsiteGate
{
    public function __construct(
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProjector $projector,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
    ) {}

    /**
     * The platform/surface this URL belongs to, or null when it is a plain
     * website. Own-infrastructure hosts (partna.au) count as "ours" too.
     */
    public function platformFor(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'partna.au' || str_ends_with($host, '.partna.au')) {
            return $host === '' ? null : 'partna';
        }

        try {
            $projection = $this->projector->project($this->canonicalizer->canonicalize($url));
            if ($projection->matched() && $projection->surfaceKey !== null) {
                return $projection->surfaceKey;
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $classified = $this->harvester->classify($url);
            if (is_array($classified) && isset($classified['platform'])) {
                return (string) $classified['platform'];
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    public function isPlatformUrl(?string $url): bool
    {
        return $this->platformFor($url) !== null;
    }

    /**
     * Send a platform URL where it belongs instead of archiving it. Best
     * effort: routing failures are reported and never break the caller's
     * write. Returns the router's verdict, or null when nothing ran.
     */
    public function divert(User $user, string $url, string $origin): ?string
    {
        try {
            $result = $this->routing->route(trim($url), RoutingContext::forUser($user, $origin));
            Log::info('platforms.previous_website_gate.diverted', [
                'user_id' => (string) $user->id,
                'origin' => $origin,
                'host' => parse_url($url, PHP_URL_HOST),
                'verdict' => $result['verdict'] ?? null,
                'routed_to' => $result['routedTo'] ?? null,
                'connection_id' => $result['connectionId'] ?? null,
            ]);

            return is_string($result['verdict'] ?? null) ? $result['verdict'] : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
