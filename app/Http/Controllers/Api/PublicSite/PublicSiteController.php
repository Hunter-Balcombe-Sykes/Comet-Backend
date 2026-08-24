<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Cache\SiteCacheService;
use App\Services\Streaming\LiveStatusInjector;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Serves published mini-site data by subdomain (cached, 95% of traffic).
class PublicSiteController extends ApiController
{
    public function __construct(
        private SiteCacheService $siteCache,
        private LiveStatusInjector $liveStatus,
    ) {}

    public function show(PublicSiteShowRequest $request): Response
    {
        $subdomain = strtolower($request->validated()['subdomain']);

        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($this->liveStatus->injectIntoPayload($payload));
        }

        // Only an ACTIVE (non-expired) alias resolves — a lapsed alias must 404,
        // not redirect, even before the prune job hard-deletes the row.
        $alias = SiteSubdomainAlias::query()
            ->active()
            ->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)])
            ->first();

        if ($alias) {
            $site = Site::query()->find($alias->site_id);

            if ($site) {
                // Only redirect if the canonical site is actually published (exists in payload view)
                $canonicalPayload = $this->siteCache->getPublicSitePayload($site->subdomain);

                if ($canonicalPayload) {
                    $host = $site->subdomain.'.'.config('partna.public_domain');
                    $url = $request->getScheme().'://'.$host.$request->getRequestUri();

                    // EDGE-1/CFG-3 (audit): explicit Cache-Control so browsers don't
                    // heuristically cache this 301 "forever". CFG-3 originally bounded
                    // that to a 5-minute re-check window; PGR-17 (audit) tightens it
                    // further to no caching at all — a rapid handle reclaim can hand
                    // the old subdomain to a new owner inside that window, and a
                    // browser that's still honouring the stale 301 would misdirect the
                    // visitor to the new owner's site instead of re-checking.
                    //
                    // EDGE-2 (audit): deliberately preserves the visited path/query
                    // (unlike showByHeader() below, which always redirects to the
                    // homepage) — this endpoint is bound to the fixed /public/site
                    // path on the subdomain host and serves a JSON API client, not
                    // the Next.js proxy's path-based routing. Do not "unify" these;
                    // they legitimately serve different consumers.
                    return redirect()->to($url, 301)
                        ->header('Cache-Control', 'private, max-age=0, must-revalidate');
                }
            }
        }

        return $this->error('Site not found.', 404);
    }

    /**
     * Header-based fallback for public site lookup.
     * Reads subdomain from X-Site-Subdomain header instead of domain routing.
     * Used by the Next.js frontend proxy for path-based routing (e.g. /slug).
     */
    public function showByHeader(Request $request)
    {
        $subdomain = $request->header('X-Site-Subdomain');
        if (! $subdomain || ! is_string($subdomain)) {
            return $this->error('Missing X-Site-Subdomain header.', 400);
        }

        // Enforce the same rules as PublicSiteShowRequest (max:63, alphanumeric-hyphen)
        // so the header path and the routed path agree on what's a valid subdomain.
        $subdomain = $this->validateSubdomainString($subdomain);

        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($this->liveStatus->injectIntoPayload($payload));
        }

        // Only an ACTIVE (non-expired) alias resolves.
        $alias = SiteSubdomainAlias::query()
            ->active()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($alias) {
            // 301 to the canonical subdomain instead of serving the page under the
            // old alias host (anti-duplicate-content). Unlike the old behaviour, the
            // redirect fires on alias resolution regardless of cache warmth — the
            // canonical request that follows will populate/serve the payload.
            $site = Site::query()->where('is_published', true)->find($alias->site_id);
            if ($site) {
                $host = $site->subdomain.'.'.config('partna.public_domain');
                $url = $request->getScheme().'://'.$host.'/';

                // EDGE-2 (audit): always redirects to the homepage rather than the
                // visited path — this is the Next.js proxy's path-based routing
                // fallback, where the canonical request that follows will populate/
                // serve the right page itself. show() above preserves the full path
                // because it serves a different (fixed-path, JSON) consumer. Do not
                // "unify" these; see the matching comment there.
                //
                // PGR-17 (audit): see the matching Cache-Control comment in show()
                // above — no caching, so a rapid handle reclaim can't strand a
                // visitor on the previous owner's site for the old TTL window.
                return redirect()->to($url, 301)
                    ->header('Cache-Control', 'private, max-age=0, must-revalidate');
            }
        }

        return $this->error('Site not found.', 404);
    }

    /**
     * Normalise and validate a raw subdomain value (from header or route).
     * Mirrors the rules enforced by PublicSiteShowRequest: max 63 chars,
     * only lowercase letters, digits, and hyphens. Aborts 400 on violation.
     */
    private function validateSubdomainString(string $raw): string
    {
        $subdomain = strtolower(trim($raw));

        if (strlen($subdomain) > 63 || ! preg_match('/^[a-z0-9-]+$/i', $subdomain)) {
            abort(response()->json(['message' => 'Invalid X-Site-Subdomain header.'], 400));
        }

        return $subdomain;
    }
}
