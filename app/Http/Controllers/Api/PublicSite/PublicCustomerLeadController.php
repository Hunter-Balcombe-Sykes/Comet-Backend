<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\PublicFeature;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\GatesPublicFeature;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\CustomerLeads\PublicCustomerLeadRequest;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Notifications\EmailSubscription;
use App\Services\Analytics\AnalyticsEventSanitizer;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Captures lead form submissions with spam detection (honeypot, timing). Creates/updates customers with marketing subscription handling.
class PublicCustomerLeadController extends ApiController
{
    use GatesPublicFeature;
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    public function store(PublicCustomerLeadRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        $marketingOptIn = (bool) ($data['marketing_opt_in'] ?? false);

        $subdomain = $this->resolveSiteSubdomain($request);

        // 1) Honeypot: if filled, pretend success but do nothing
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, null, 'honeypot', $data['form_started_at_ms'] ?? null);

            return $this->success(['ok' => true], 201);
        }

        // 2) Timing check (optional field, but enforced if provided)
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;

            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                $this->logLead($request, $subdomain, null, null, null, 'too_fast', $startedMs);

                return $this->error('Invalid submission.', 422);
            }
        }

        if (! $subdomain) {
            $this->logLead($request, null, null, null, null, 'no_subdomain', $startedMs);

            return $this->error('Could not determine site from URL.', 400);
        }

        // Resolve to the canonical site (the resolver transparently follows an
        // active alias). We deliberately do NOT 301 on an alias hit: a 301 on a
        // POST is downgraded to GET by most clients, which would drop the lead.
        $site = $resolver->resolvePublishedSite($subdomain)['site'];

        if (! $site) {
            $this->logLead($request, $subdomain, null, null, null, 'site_not_found', $startedMs);

            return $this->error('Site not found.', 404);
        }

        if (! $site->user_id) {
            $this->logLead($request, $subdomain, $site->id, null, null, 'site_unlinked', $startedMs);

            return $this->error('Site is not linked to a professional.', 422);
        }

        // 'professional' relation was renamed to 'user' (commit 69496a0c); this
        // loadMissing straggler was missed and 500'd every valid submission.
        $site->loadMissing('user');

        if (! $site->user) {
            $this->logLead($request, $subdomain, $site->id, null, null, 'site_unlinked', $startedMs);

            return $this->error('Site is not linked to a professional.', 422);
        }

        $pro = $site->user;

        // Staff feature kill-switch.
        $this->assertPublicFeatureAvailable($site, PublicFeature::CustomerLeads);

        // Check if customer with this email already exists (excluding soft-deleted)
        $customer = $pro->customers()
            ->where('email', $data['email'])
            ->first();

        if ($customer) {
            // Update existing customer with new data
            $customer->update([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? $customer->phone,
                'notes' => $data['notes'] ?? $customer->notes,
                // Don't overwrite external_id if already set
                'external_id' => $data['external_id'] ?? $customer->external_id,
            ]);
        } else {
            // Create new customer (defaults to true via database)
            $customer = $pro->customers()->create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'notes' => $data['notes'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'source' => 'site',
                'marketing_opt_in_cached' => ! $marketingOptIn ? false : null, // only set to false if explicitly opted out
            ]);
        }

        if ($marketingOptIn && ! empty($data['email'])) {
            $this->upsertMarketingSubscription(
                userId: $pro->id,
                email: (string) $data['email'],
                fullName: $data['full_name'] ?? null,
                request: $request
            );
        }

        $this->logLead($request, $subdomain, $site->id, $pro->id, $customer->id, 'created', $startedMs);

        return $this->success([
            'ok' => true,
            'customer_id' => $customer->id,
        ], 201);
    }

    private function logLead(
        Request $request,
        ?string $subdomain,
        ?string $siteId,
        ?string $userId,
        ?string $customerId,
        string $outcome,
        ?int $formStartedAtMs
    ): void {
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain,
            'site_id' => $siteId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'ip_hash' => $this->hashIp($request->ip()),
            // PRIV-5/6: cap the UA and strip referrer query strings (UTM PII).
            'user_agent' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
            'referrer' => AnalyticsEventSanitizer::referrer($request->headers->get('referer')),
            'outcome' => $outcome,
            'form_started_at_ms' => $formStartedAtMs,
        ]);
    }

    private function upsertMarketingSubscription(string $userId, string $email, ?string $fullName, Request $request): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $listKey = 'marketing';

        try {
            $sub = EmailSubscription::query()
                ->where('user_id', $userId)
                ->where('list_key', $listKey)
                ->where('email_lc', $email)
                ->first();

            if (! $sub) {
                $sub = new EmailSubscription([
                    'user_id' => $userId,
                    'list_key' => $listKey,
                    'email' => $email,
                    'email_lc' => $email,
                    'full_name' => $fullName,
                    'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                ]);
            } else {
                if ($fullName) {
                    $sub->full_name = $fullName;
                }
                if (! $sub->unsubscribe_token) {
                    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                }
            }

            $sub->markSubscribed([
                'source' => 'site_lead',
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
            ]);

            $sub->save();
        } catch (UniqueConstraintViolationException) {
            // Race: another request inserted the same (user_id, list_key, email_lc)
            // row between our SELECT and INSERT. Idempotent — nothing more to do.
        }
    }
}
