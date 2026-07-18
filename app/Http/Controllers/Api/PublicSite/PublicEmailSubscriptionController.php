<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\PublicFeature;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\GatesPublicFeature;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Http\Requests\Api\PublicSite\PublicEmailSubscribeRequest;
use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\User\Customer;
use App\Services\PublicSite\PublicSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

// V2: Newsletter signup with name inference from email and customer upsert.
class PublicEmailSubscriptionController extends ApiController
{
    use GatesPublicFeature;
    use HashesClientData;
    use ResolvesSubdomainFromHost;

    private const COMMON_FIRST_NAMES = [
        'aaron', 'adam', 'alex', 'alice', 'amanda', 'amelia', 'amy', 'andrew', 'anna', 'anthony',
        'ashley', 'ben', 'benjamin', 'blake', 'brad', 'brandon', 'brian', 'caitlin', 'cameron', 'charlotte',
        'chloe', 'chris', 'christopher', 'claire', 'dan', 'daniel', 'david', 'dylan', 'edward', 'ella',
        'emily', 'emma', 'ethan', 'eva', 'george', 'grace', 'hannah', 'harry', 'holly', 'isabella',
        'isla', 'jack', 'jacob', 'jake', 'james', 'jasmine', 'jason', 'jess', 'jessica', 'jordan',
        'josh', 'joshua', 'katie', 'lauren', 'liam', 'lily', 'luke', 'madison', 'matt', 'matthew',
        'megan', 'mia', 'michael', 'nathan', 'nicholas', 'noah', 'olivia', 'oscar', 'patrick', 'paul',
        'rachel', 'rebecca', 'ryan', 'sam', 'samantha', 'samuel', 'sarah', 'scott', 'sean', 'sophia',
        'stephanie', 'steven', 'thomas', 'toby', 'tom', 'victoria', 'will', 'william', 'zach', 'zoe',
    ];

    private const NON_NAME_TOKENS = [
        'admin', 'booking', 'bookings', 'contact', 'hello', 'help', 'info', 'mail', 'marketing',
        'newsletter', 'noreply', 'reply', 'sales', 'shop', 'store', 'support', 'team', 'test',
    ];

    public function subscribe(PublicEmailSubscribeRequest $request, PublicSiteResolver $resolver): JsonResponse
    {
        $data = $request->validated();

        // Bot protection — mirrors PublicCustomerLeadController.

        // 1) Honeypot: real browsers leave this hidden field empty; bots fill it.
        //    Pretend success so bots can't fingerprint the gate.
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            Log::info('email_subscribe.honeypot_hit', ['email_hash' => hash('sha256', (string) ($data['email'] ?? ''))]);

            return $this->success(['ok' => true, 'subscribed' => true, 'list_key' => $data['list_key'] ?? 'marketing']);
        }

        // 2) Timing check: enforce only when form_started_at_ms is present.
        //    (Field is nullable until the frontend sends it — tighten to required then.)
        $startedMs = $data['form_started_at_ms'] ?? null;
        if (is_int($startedMs)) {
            $nowMs = (int) floor(microtime(true) * 1000);
            $delta = $nowMs - $startedMs;
            $minMs = (int) config('partna.form_timing.min_ms', 2500);
            $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);

            if ($delta < $minMs || $delta > $maxMs) {
                return $this->error('Invalid submission.', 422);
            }
        }

        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Could not determine site from URL.', 400);
        }

        // Resolve to the canonical site (the resolver transparently follows an
        // active alias). No 301 on an alias hit — a 301 on a POST would be
        // downgraded to GET by most clients and silently drop the subscription.
        $site = $resolver->resolvePublishedSite($subdomain)['site'];

        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        // Staff feature kill-switch.
        $this->assertPublicFeatureAvailable($site, PublicFeature::EmailSignup);

        $listKey = $data['list_key'] ?? 'marketing';

        $email = strtolower(trim($data['email']));
        $providedName = is_string($data['full_name'] ?? null) ? trim((string) $data['full_name']) : '';
        $resolvedName = $providedName !== '' ? $providedName : $this->inferNameFromEmail($email);
        $overwriteName = $providedName !== '';

        $subscription = EmailSubscription::query()
            ->where('user_id', $site->user_id)
            ->where('list_key', $listKey)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if (! $subscription) {
            $subscription = new EmailSubscription([
                'user_id' => $site->user_id,
                'list_key' => $listKey,
                'email' => $email,
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
            ]);
        } else {
            $subscription->email = $email;
            if (! $subscription->unsubscribe_token) {
                $subscription->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
            }
        }

        // Whether this request is a *genuine* opt-in: a brand-new row, or a
        // previously-unsubscribed address opting back in. A redundant re-submit
        // of an already-subscribed address must NOT re-confirm (anti-abuse).
        $isNewSubscription = ! $subscription->exists;
        $priorStatus = $isNewSubscription ? null : $subscription->status;
        $isGenuineOptIn = $isNewSubscription || $priorStatus === 'unsubscribed';

        if ($resolvedName) {
            $existingName = is_string($subscription->full_name ?? null) ? trim((string) $subscription->full_name) : '';
            if ($overwriteName || $existingName === '') {
                $subscription->full_name = $resolvedName;
            }
        }

        $subscription->email_lc = $email;

        $subscription->markSubscribed([
            'source' => 'site_subscribe',
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
        ]);

        // A genuine re-subscribe should confirm again — clear the prior stamp.
        if ($isGenuineOptIn) {
            $subscription->confirmation_sent_at = null;
        }

        $subscription->save();

        try {
            $this->upsertMarketingCustomer(
                (string) $site->user_id,
                $email,
                $resolvedName,
                $overwriteName,
            );
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails — but DO
            // surface to Nightwatch so silent customer/list drift isn't invisible.
            report($exception);
            // Hash the email — Nightwatch / log aggregator retention exceeds
            // GDPR scrubbing scope; a hash preserves cross-reference ability
            // without storing raw PII in the log.
            Log::warning('Public subscribe customer upsert failed', [
                'user_id' => (string) $site->user_id,
                'email_hash' => hash('sha256', $email),
                'error' => $exception->getMessage(),
            ]);
        }

        if ($isGenuineOptIn) {
            SendSubscriptionConfirmationJob::dispatch((string) $subscription->id);
        }

        return $this->success([
            'ok' => true,
            'subscribed' => true,
            'list_key' => $listKey,
        ]);
    }

    private function upsertMarketingCustomer(
        string $userId,
        string $email,
        ?string $fullName,
        bool $overwriteName = false,
    ): void {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $name = is_string($fullName) ? trim($fullName) : '';

        $existing = Customer::query()
            ->withTrashed()
            ->where('user_id', $userId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existingName = is_string($existing->full_name ?? null) ? trim((string) $existing->full_name) : '';
            if ($name !== '' && ($overwriteName || $existingName === '')) {
                $existing->full_name = $name;
            }

            if (($existing->source ?? '') === '') {
                $existing->source = 'site';
            }

            $existing->save();

            return;
        }

        $customer = new Customer;
        $customer->user_id = $userId;
        $customer->email = $normalizedEmail;
        $customer->full_name = $name !== '' ? $name : null;
        $customer->source = 'site';
        $customer->save();
    }

    private function inferNameFromEmail(string $email): ?string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || ! str_contains($normalized, '@')) {
            return null;
        }

        [$localPart] = explode('@', $normalized, 2);
        $localPart = preg_replace('/\+.*$/', '', $localPart) ?? $localPart;
        $localPart = strtolower(trim($localPart));
        if ($localPart === '') {
            return null;
        }

        if (in_array($localPart, self::NON_NAME_TOKENS, true)) {
            return null;
        }

        if (preg_match('/^[a-z]{2,24}$/', $localPart) === 1) {
            if (in_array($localPart, self::COMMON_FIRST_NAMES, true)) {
                return ucfirst($localPart);
            }

            foreach (self::COMMON_FIRST_NAMES as $candidateFirstName) {
                if (! str_starts_with($localPart, $candidateFirstName)) {
                    continue;
                }
                $remaining = substr($localPart, strlen($candidateFirstName));
                if (strlen($remaining) < 2 || strlen($remaining) > 24) {
                    continue;
                }
                if (in_array($remaining, self::NON_NAME_TOKENS, true)) {
                    continue;
                }

                return ucfirst($candidateFirstName).' '.ucfirst($remaining);
            }

            return ucfirst($localPart);
        }

        $parts = preg_split('/[^a-z]+/', $localPart) ?: [];
        $parts = array_values(array_filter($parts, static function ($part): bool {
            $length = strlen($part);

            return $length >= 2 && $length <= 24;
        }));

        if (count($parts) === 0 || count($parts) > 3) {
            return null;
        }

        $first = $parts[0];
        if (in_array($first, self::NON_NAME_TOKENS, true)) {
            return null;
        }

        if (count($parts) === 1) {
            return ucfirst($first);
        }

        $last = $parts[1];
        if (in_array($last, self::NON_NAME_TOKENS, true)) {
            return ucfirst($first);
        }

        return ucfirst($first).' '.ucfirst($last);
    }
}
