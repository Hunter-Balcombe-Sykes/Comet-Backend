<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\SetCustomDomainRequest;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflareCustomHostnameService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

// Custom domains (Cloudflare for SaaS). A user connects their own domain
// (bookwith.me) instead of <handle>.partna.au:
//   1. PUT  /me/site/custom-domain        — store the domain + create a CF custom
//      hostname (status 'pending'); returns the CNAME the user must add.
//   2. user points <domain> CNAME → cname.partna.au; CF validates + issues a cert.
//   3. POST /me/site/custom-domain/verify — re-poll CF; when active, flip status to
//      'active' so the KV sync publishes the route.
//   4. DELETE /me/site/custom-domain      — tear down the CF hostname + KV entry.
//
// KV writes go through SyncSubdomainToKvJob (the single SUBDOMAIN_KV writer) — this
// controller never touches KV directly.
class CustomDomainController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly CloudflareCustomHostnameService $cf) {}

    public function show(Request $request): JsonResponse
    {
        return $this->success($this->state($this->siteOrFail($request)));
    }

    public function store(SetCustomDomainRequest $request): JsonResponse
    {
        if (! $this->cf->configured()) {
            return $this->error('Custom domains aren’t available yet — Cloudflare for SaaS isn’t configured.', 503);
        }

        $user = $this->currentUser($request);
        $site = $this->siteOrFail($request);
        $domain = $request->validated()['domain'];

        // Authorize BEFORE any Cloudflare side effect — not merely before
        // $site->save() (SEC-108). Both the previous-hostname teardown below
        // and the create() call further down talk to a real external system;
        // a denied write must never leave a live custom hostname behind with
        // no cleanup path (create() succeeding, then the save being denied,
        // would orphan it). Defence-in-depth: $site is already resolved off
        // the authenticated caller's OWN relation, so this can't actually
        // deny today — it's a guard for a future by-UUID path.
        $this->authorizeForUser($user, 'update', $site);

        // Reject a domain already connected to a different site.
        $takenByOther = Site::query()
            ->whereRaw('lower(custom_domain) = ?', [$domain])
            ->where('id', '!=', $site->id)
            ->exists();
        if ($takenByOther) {
            return $this->error('That domain is already connected to another Partna site.', 422);
        }

        $previous = $site->custom_domain ? strtolower($site->custom_domain) : null;

        // Tear down the previous CF hostname when the domain is actually changing.
        if ($site->custom_domain_cf_id && $previous !== $domain) {
            try {
                $this->cf->delete($site->custom_domain_cf_id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $created = $this->cf->create($domain);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Could not start domain verification with Cloudflare. Please try again.', 502);
        }

        $active = ($created['status'] ?? null) === 'active' && ($created['ssl_status'] ?? null) === 'active';
        $site->custom_domain = $domain;
        $site->custom_domain_cf_id = $created['id'] ?? null;
        $site->custom_domain_status = $active ? 'active' : 'pending';
        $site->custom_domain_verified_at = $active ? now() : null;
        // Connecting a domain means the user wants to use it: an instantly-active
        // domain becomes their primary URL; a pending one can't be primary yet
        // (it gets promoted on first verification — see verify()).
        $site->custom_domain_primary = $active;

        try {
            $site->save();
        } catch (UniqueConstraintViolationException $e) {
            // Lost a TOCTOU race: another site claimed this domain between our pre-check
            // and this save. Tear down the CF hostname we just created so it isn't
            // orphaned, then return the same 422 the pre-check path returns.
            try {
                $this->cf->delete((string) ($created['id'] ?? ''));
            } catch (Throwable $cleanup) {
                report($cleanup);
            }

            return $this->error('That domain is already connected to another Partna site.', 422);
        }

        // Retire the old domain's KV entry; the job writes the new one once active.
        SyncSubdomainToKvJob::dispatch((string) $site->user_id, null, $previous && $previous !== $domain ? $previous : null);

        return $this->success([...$this->state($site), 'cloudflare' => $created]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->siteOrFail($request);

        if (! $site->custom_domain || ! $site->custom_domain_cf_id) {
            return $this->error('No custom domain to verify.', 404);
        }
        if (! $this->cf->configured()) {
            return $this->error('Custom domains aren’t available yet.', 503);
        }

        try {
            $status = $this->cf->get($site->custom_domain_cf_id);
        } catch (Throwable $e) {
            report($e);

            return $this->error('Could not read domain status from Cloudflare.', 502);
        }

        $active = ($status['status'] ?? null) === 'active' && ($status['ssl_status'] ?? null) === 'active';
        $broken = in_array($status['status'] ?? '', ['blocked', 'moved', 'deleted'], true);

        $site->custom_domain_status = $active ? 'active' : ($broken ? 'error' : 'pending');
        if ($active && ! $site->custom_domain_verified_at) {
            $site->custom_domain_verified_at = now();
            // First successful verification promotes the domain to the user's
            // primary URL — verifying means they want to use it. They can switch
            // back to their Partna handle anytime from settings.
            $site->custom_domain_primary = true;
        }
        // Ownership gate immediately before the write (SEC-108, defence-in-
        // depth — see siteOrFail()'s comment).
        $this->authorizeForUser($user, 'update', $site);
        $site->save();

        // Once active, publish the domain → handle route through the single writer.
        if ($active) {
            SyncSubdomainToKvJob::dispatch((string) $site->user_id);
        }

        return $this->success([...$this->state($site), 'cloudflare' => $status]);
    }

    // POST /me/site/custom-domain/primary — choose the canonical sitepage URL.
    // primary=true promotes the custom domain (dashboard links, previews, share
    // targets all switch to it); only permitted once the domain is verified
    // active. primary=false swaps back to <handle>.partna.au.
    public function setPrimary(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->siteOrFail($request);
        $primary = $request->boolean('primary');

        if ($primary && $site->custom_domain_status !== 'active') {
            return $this->error('Verify your domain before making it your primary URL.', 422);
        }

        $site->custom_domain_primary = $primary;
        // Ownership gate immediately before the write (SEC-108, defence-in-
        // depth — see siteOrFail()'s comment).
        $this->authorizeForUser($user, 'update', $site);
        $site->save();

        return $this->success($this->state($site));
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->siteOrFail($request);

        // Authorize BEFORE any Cloudflare side effect — not merely before
        // $site->save() (SEC-108). The teardown below talks to a real external
        // system; a denied write must never tear down a live custom hostname
        // before being denied. Defence-in-depth: $site is already resolved off
        // the authenticated caller's OWN relation, so this can't actually
        // deny today — it's a guard for a future by-UUID / staff path.
        $this->authorizeForUser($user, 'update', $site);

        $previous = $site->custom_domain ? strtolower($site->custom_domain) : null;

        if ($site->custom_domain_cf_id) {
            try {
                $this->cf->delete($site->custom_domain_cf_id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $site->custom_domain = null;
        $site->custom_domain_cf_id = null;
        $site->custom_domain_status = null;
        $site->custom_domain_verified_at = null;
        $site->custom_domain_primary = false;
        $site->save();

        if ($previous) {
            SyncSubdomainToKvJob::dispatch((string) $site->user_id, null, $previous);
        }

        return $this->success($this->state($site));
    }

    private function siteOrFail(Request $request): Site
    {
        $user = $this->currentUser($request);
        $site = $user->site;
        abort_unless($site !== null, 404, 'No site to configure.');

        // Read-only ownership gate, covering show() and the entry point every
        // mutator resolves through — each mutator layers its own 'update'
        // authorize immediately before its own write (SEC-108), so this stays
        // view-only and show() remains a pure read. Defence-in-depth: $site is
        // already the authenticated caller's OWN relation, so this can't
        // actually deny today — it's a guard for a future by-UUID path.
        $this->authorizeForUser($user, 'view', $site);

        return $site;
    }

    /** @return array<string, mixed> */
    private function state(Site $site): array
    {
        return [
            'domain' => $site->custom_domain,
            'status' => $site->custom_domain_status,
            'primary' => (bool) $site->custom_domain_primary,
            'verified_at' => $site->custom_domain_verified_at?->toIso8601String(),
            'cname_target' => $this->cf->cnameTarget(),
        ];
    }
}
