<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\SetCustomDomainRequest;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflareCustomHostnameService;
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

        $site = $this->siteOrFail($request);
        $domain = $request->validated()['domain'];

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
        $site->save();

        // Retire the old domain's KV entry; the job writes the new one once active.
        SyncSubdomainToKvJob::dispatch((string) $site->user_id, null, $previous && $previous !== $domain ? $previous : null);

        return $this->success([...$this->state($site), 'cloudflare' => $created]);
    }

    public function verify(Request $request): JsonResponse
    {
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
        }
        $site->save();

        // Once active, publish the domain → handle route through the single writer.
        if ($active) {
            SyncSubdomainToKvJob::dispatch((string) $site->user_id);
        }

        return $this->success([...$this->state($site), 'cloudflare' => $status]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $site = $this->siteOrFail($request);
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
        $site->save();

        if ($previous) {
            SyncSubdomainToKvJob::dispatch((string) $site->user_id, null, $previous);
        }

        return $this->success($this->state($site));
    }

    private function siteOrFail(Request $request): Site
    {
        $site = $this->currentUser($request)->site;
        abort_unless($site !== null, 404, 'No site to configure.');

        return $site;
    }

    /** @return array<string, mixed> */
    private function state(Site $site): array
    {
        return [
            'domain' => $site->custom_domain,
            'status' => $site->custom_domain_status,
            'verified_at' => $site->custom_domain_verified_at?->toIso8601String(),
            'cname_target' => $this->cf->cnameTarget(),
        ];
    }
}
