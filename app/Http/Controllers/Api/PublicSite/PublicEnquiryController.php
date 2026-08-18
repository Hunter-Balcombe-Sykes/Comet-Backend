<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\PublicFeature;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\GatesPublicFeature;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\PublicEnquiryRequest;
use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Services\Analytics\AnalyticsEventSanitizer;
use App\Services\Notifications\EnquirySpamBlocklist;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

// V2: Handles public contact form submissions. Saves enquiry, upserts submitter as Customer lead, dispatches notification email.
class PublicEnquiryController extends ApiController
{
    use GatesPublicFeature;
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    public function submit(PublicEnquiryRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();

        $subdomain = $this->resolveSiteSubdomain($request);
        $startedMs = $data['form_started_at_ms'] ?? null;

        // 1) Honeypot: pretend success but record the abuse attempt.
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, 'honeypot', $startedMs);

            return $this->success(['ok' => true]);
        }

        // 2) Timing check: reject fires that are implausibly fast or old.
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;
            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                $this->logLead($request, $subdomain, null, null, 'too_fast', $startedMs);

                return $this->error('Invalid submission.', 422);
            }
        }

        if (! $subdomain) {
            $this->logLead($request, null, null, null, 'no_subdomain', $startedMs);

            return $this->error('Could not determine site from URL.', 400);
        }

        // Resolve to the canonical site (the resolver transparently follows an
        // active alias). No 301 on an alias hit — a 301 on a POST would be
        // downgraded to GET by most clients and silently drop the enquiry.
        $site = $resolver->resolvePublishedSite($subdomain)['site'];
        if (! $site || ! $site->user_id) {
            $this->logLead($request, $subdomain, $site?->id, null, 'site_not_found', $startedMs);

            return $this->error('Site not found.', 404);
        }

        // Staff feature kill-switch: 422 before the contact-block check so a
        // disabled rule takes precedence over "no contact block".
        $this->assertPublicFeatureAvailable($site, PublicFeature::Enquiries);

        // 3) Contact block must be active on this site.
        $block = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $block) {
            return $this->error('This site is not accepting enquiries.', 422);
        }

        // 4a) Spam pre-check: silently accept but discard if sender is blocklisted.
        // Returns identical 200 response so blocklisted senders can't probe for their status.
        if (app(EnquirySpamBlocklist::class)->contains((string) $site->user_id, $data['email'])) {
            return $this->success(['ok' => true]);
        }

        // 4b) Validate subject against merged options (platform defaults + affiliate additions).
        $defaults = (array) config('partna.contact_subject_defaults', []);
        $custom = data_get($block->settings, 'subject_options');
        $custom = is_array($custom) ? $custom : [];
        $mergedOptions = array_values(array_unique(array_merge($defaults, $custom)));

        if (! in_array($data['subject'], $mergedOptions, true)) {
            return $this->error('Invalid subject.', 422);
        }

        // 5) Upsert submitter as Customer lead so we can link customer_id on the enquiry.
        // Wrap in try/catch to handle the rare concurrent-insert race: two identical submissions
        // arriving simultaneously can both pass the SELECT check then both attempt INSERT.
        try {
            $customer = $this->upsertEnquiryCustomer((string) $site->user_id, $data['email'], $data['name'], $data['phone'] ?? null);
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent submission won the insert race; re-fetch the winner.
            $customer = Customer::query()
                ->where('user_id', $site->user_id)
                ->whereRaw('lower(email) = ?', [strtolower($data['email'])])
                ->firstOrFail();
        }

        // 6) Save the enquiry, linking the upserted customer. user_id/site_id/
        // customer_id are not fillable (tenancy FKs) — associate() sets them
        // explicitly from server-resolved models, never from client input.
        $enquiry = new Enquiry([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'ip_hash' => $this->hashIp($request->ip()),
            // PGR-19: coarse UA token, matching logLead() below and
            // PublicCustomerLeadController's PRIV-2 pattern — was capped at
            // 500 chars but still stored the raw string.
            'user_agent' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
        ]);
        $enquiry->user()->associate($site->user_id);
        $enquiry->site()->associate($site);
        $enquiry->customer()->associate($customer);
        $enquiry->save();

        // 7) Log unified lead analytics.
        $this->logLead($request, $subdomain, $site->id, (string) $site->user_id, 'created', $startedMs);

        // 8/9) Queue notification dispatch off the hot path, then the visitor's
        // receipt. Both are guarded: the enquiry row is ALREADY COMMITTED above,
        // and there is no surrounding transaction, so an unguarded dispatch
        // fault (dead Redis queue) turned a saved lead into a 500 the visitor
        // would retry — producing duplicate enquiries and no notification ever.
        // Drill 03 (2026-08-06) finding 3.
        $this->dispatchEnquiryNotifications($enquiry);

        return $this->success(['ok' => true]);
    }

    /**
     * Dispatch both notification jobs, stamping the enquiry for reconciliation
     * if the queue is unreachable.
     *
     * ONE marker for TWO jobs, deliberately. A Redis fault kills both calls
     * microseconds apart, so the realistic case is all-or-nothing; in the split
     * case re-dispatching both is safe, because each job carries its own
     * post-send idempotency stamp (email_sent_at / confirmation_sent_at) and
     * SendEnquiryConfirmationJob is additionally ShouldBeUnique.
     */
    private function dispatchEnquiryNotifications(Enquiry $enquiry): void
    {
        try {
            DispatchEnquiryNotificationsJob::dispatch((string) $enquiry->id);
            SendEnquiryConfirmationJob::dispatch((string) $enquiry->id);
        } catch (Throwable $e) {
            Log::warning('enquiry.notify.dispatch_failed', [
                'enquiry_id' => (string) $enquiry->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            try {
                $enquiry->forceFill(['notifications_pending_since' => now()])->save();
            } catch (Throwable $markerError) {
                // Postgres is gone too. Nothing left to do but report — never
                // let this turn the visitor's successful submission into a 500.
                report($markerError);
            }
        }
    }

    private function upsertEnquiryCustomer(string $userId, string $email, ?string $fullName, ?string $phone): Customer
    {
        $normalizedEmail = strtolower(trim($email));

        $existing = Customer::query()
            ->withTrashed()
            ->where('user_id', $userId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            if ($fullName && trim((string) ($existing->full_name ?? '')) === '') {
                $existing->full_name = $fullName;
            }

            if ($phone && trim((string) ($existing->phone ?? '')) === '') {
                $existing->phone = $phone;
            }

            if (($existing->source ?? '') === '') {
                $existing->source = 'enquiry';
            }

            $existing->save();

            return $existing;
        }

        $customer = new Customer;
        $customer->user_id = $userId;
        $customer->email = $normalizedEmail;
        $customer->full_name = $fullName ?: null;
        $customer->phone = $phone ?: null;
        $customer->source = 'enquiry';
        $customer->save();

        return $customer;
    }

    private function logLead(
        Request $request,
        ?string $subdomain,
        ?string $siteId,
        ?string $userId,
        string $outcome,
        ?int $formStartedAtMs,
    ): void {
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain,
            'site_id' => $siteId,
            'user_id' => $userId,
            'customer_id' => null,
            'ip_hash' => $this->hashIp($request->ip()),
            // PRIV-5/6: cap the UA and strip referrer query strings (UTM PII).
            'user_agent' => AnalyticsEventSanitizer::userAgent($request->userAgent()),
            'referrer' => AnalyticsEventSanitizer::referrer($request->headers->get('referer')),
            'outcome' => $outcome,
            'form_started_at_ms' => $formStartedAtMs,
        ]);
    }
}
