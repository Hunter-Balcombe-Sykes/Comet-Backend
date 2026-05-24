<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\PublicEnquiryRequest;
use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Analytics\LeadSubmission;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// V2: Handles public contact form submissions. Saves enquiry, upserts submitter as Customer lead, dispatches notification email.
class PublicEnquiryController extends ApiController
{
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

        $site = $resolver->resolvePublishedSite($subdomain);
        if (! $site || ! $site->professional_id) {
            $this->logLead($request, $subdomain, $site?->id, null, 'site_not_found', $startedMs);

            return $this->error('Site not found.', 404);
        }

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

        // 4) Validate subject against merged options (platform defaults + affiliate additions).
        $defaults = (array) config('partna.contact_subject_defaults', []);
        $custom = data_get($block->settings, 'subject_options');
        $custom = is_array($custom) ? $custom : [];
        $mergedOptions = array_values(array_unique(array_merge($defaults, $custom)));

        if (! in_array($data['subject'], $mergedOptions, true)) {
            return $this->error('Invalid subject.', 422);
        }

        // 5) Save the enquiry.
        $enquiry = Enquiry::query()->create([
            'professional_id' => $site->professional_id,
            'site_id' => $site->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        // 6) Upsert submitter as Customer lead.
        $this->upsertEnquiryCustomer((string) $site->professional_id, $data['email'], $data['name'], $data['phone'] ?? null);

        // 7) Log unified lead analytics.
        $this->logLead($request, $subdomain, $site->id, (string) $site->professional_id, 'created', $startedMs);

        // 8) Dispatch notification email (only if settings.notification_email is present and per-brand hourly limit not reached).
        // B3/P1-10: the job re-reads notification_email from $block->settings inside
        // handle() — we only pass UUIDs across the queue boundary so the brand's
        // inbox address never sits in a serialised Redis payload.
        $notificationEmail = data_get($block->settings, 'notification_email');
        if (is_string($notificationEmail) && trim($notificationEmail) !== '') {
            $notifyKey = 'enquiry_notify:'.$site->professional_id;
            $notifyLimit = config('partna.throttle.enquiry_notification_per_hour', 10);

            if (! RateLimiter::tooManyAttempts($notifyKey, $notifyLimit)) {
                RateLimiter::hit($notifyKey, 3600);
                SendEnquiryNotificationJob::dispatch((string) $enquiry->id, (string) $block->id);
            }
        }

        return $this->success(['ok' => true]);
    }

    private function upsertEnquiryCustomer(string $professionalId, string $email, ?string $fullName, ?string $phone): void
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
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

            return;
        }

        $customer = new Customer;
        $customer->professional_id = $professionalId;
        $customer->email = $normalizedEmail;
        $customer->full_name = $fullName ?: null;
        $customer->phone = $phone ?: null;
        $customer->source = 'enquiry';
        $customer->save();
    }

    private function logLead(
        Request $request,
        ?string $subdomain,
        ?string $siteId,
        ?string $professionalId,
        string $outcome,
        ?int $formStartedAtMs,
    ): void {
        LeadSubmission::query()->create([
            'occurred_at' => now(),
            'subdomain' => $subdomain,
            'site_id' => $siteId,
            'professional_id' => $professionalId,
            'customer_id' => null,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $request->headers->get('referer'),
            'outcome' => $outcome,
            'form_started_at_ms' => $formStartedAtMs,
        ]);
    }
}
