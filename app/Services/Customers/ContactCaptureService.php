<?php

namespace App\Services\Customers;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralized contact capture for affiliate CRM entries.
 *
 * Handles the upsert rules for core.customers: soft-delete restore,
 * email-based dedup (matches the `(professional_id, lower(email))` unique index),
 * phone uniqueness collision retries, and source preservation (first capture wins).
 *
 * Marketing list membership lives in notifications.email_subscriptions and is
 * upserted via captureMarketingSubscription(). Call that AFTER captureContact()
 * so the EmailSubscription 'saved' hook finds the customer row and syncs the
 * marketing_opt_in_cached flag.
 *
 * All methods are non-throwing by design — contact capture is a side-effect of
 * primary business flows (Shopify order webhook, Square booking, site leads)
 * and must never fail the parent operation. Failures are logged as warnings.
 */
class ContactCaptureService
{
    /**
     * Upsert a contact on the given affiliate's customer list.
     *
     * Dedup key: (professional_id, lower(email)). Soft-deleted rows are restored
     * so a customer who unsubscribed and later re-purchases comes back into the list.
     *
     * Source preservation: the existing row's source is left untouched. Only new
     * rows or rows with an empty source get the incoming source value. This means
     * the first capture point to see a given email wins.
     *
     * full_name preservation: we only overwrite the stored full_name when the
     * incoming value is MORE substantial than the existing one (existing is
     * null/empty, or incoming is strictly longer). This protects affiliate
     * manual cleanups like "JOHN DOE" → "John Doe" from being clobbered by the
     * next raw order payload.
     *
     * Marketing consent: defaults to opted-in on NEW rows (matching the
     * core.customers schema default of true). Callers can force opt-out by
     * passing `marketing_opt_in => false`, used by the Shopify webhook path
     * when the cart carries a falsy sidest_marketing_opt_in attribute. On
     * EXISTING rows this flag is left alone — the EmailSubscription saved
     * hook is the source of truth for consent changes over time.
     *
     * Phone/email/name inputs may be any of: string, empty string, whitespace,
     * or null — the service normalizes everything, so callers can pass raw
     * payload values without guarding.
     *
     * @param  array{email:string, full_name?:?string, phone?:?string, source:string, external_id?:?string, marketing_opt_in?:?bool}  $data
     */
    public function captureContact(string $professionalId, array $data): ?Customer
    {
        try {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '') {
                return null;
            }

            $fullName = $this->normalizeNullable($data['full_name'] ?? null);
            $phone = $this->normalizeNullable($data['phone'] ?? null);

            $source = trim((string) ($data['source'] ?? ''));
            $externalId = $this->normalizeNullable($data['external_id'] ?? null);

            // Default: implicitly opted in unless the caller explicitly passed false.
            $marketingOptIn = array_key_exists('marketing_opt_in', $data) && $data['marketing_opt_in'] !== null
                ? (bool) $data['marketing_opt_in']
                : true;

            $existing = Customer::query()
                ->withTrashed()
                ->where('professional_id', $professionalId)
                ->whereRaw('lower(email) = ?', [$email])
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                // Only accept a new full_name if it's more substantial than what's
                // already stored — prevents raw order payloads from reverting
                // affiliate-curated edits ("John Doe" staying, not being clobbered
                // by "JOHN DOE").
                if ($fullName !== null && $this->isMoreSubstantial($fullName, $existing->full_name)) {
                    $existing->full_name = $fullName;
                }
                // First capture wins — only fill source on rows that don't have one.
                if (($existing->source ?? '') === '' && $source !== '') {
                    $existing->source = $source;
                }
                if ($externalId !== null && ($existing->external_id ?? '') === '') {
                    $existing->external_id = $externalId;
                }

                $this->savePreservingPhoneOnCollision($existing, $phone);

                return $existing;
            }

            // professional_id isn't in Customer::$fillable (matches existing
            // app convention — see AccountTypeDefaultsService), so we assign
            // it after construction rather than via mass-assignment.
            try {
                return $this->createCustomerRow($professionalId, $fullName, $email, $phone, $source, $externalId, $marketingOptIn);
            } catch (UniqueConstraintViolationException $e) {
                // Phone collides with another contact on the same affiliate — retry without phone.
                return $this->createCustomerRow($professionalId, $fullName, $email, null, $source, $externalId, $marketingOptIn);
            }
        } catch (Throwable $e) {
            // Surface to Nightwatch — Log::warning is a breadcrumb, only report()
            // triggers exception-based alerting. We still swallow the throw so
            // contact capture failures never break the parent business flow.
            report($e);
            Log::warning('Contact capture failed', [
                'professional_id' => $professionalId,
                'source' => $data['source'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create a Customer row with the given data. `professional_id` is set
     * directly (not via fillable) to match the rest of the app.
     */
    private function createCustomerRow(
        string $professionalId,
        ?string $fullName,
        string $email,
        ?string $phone,
        string $source,
        ?string $externalId,
        bool $marketingOptIn,
    ): Customer {
        $customer = new Customer([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'source' => $source !== '' ? $source : null,
            'external_id' => $externalId,
            'marketing_opt_in_cached' => $marketingOptIn,
        ]);
        $customer->professional_id = $professionalId;
        $customer->save();

        return $customer;
    }

    /**
     * Trim and null-normalize a nullable string input. Returns null for
     * null/empty/whitespace; returns the trimmed string otherwise.
     */
    private function normalizeNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * A replacement full_name is "more substantial" when the existing value is
     * empty OR the incoming value is strictly longer (a longer string usually
     * means more complete data — "John" < "John Doe" < "John Q. Doe"). Equal or
     * shorter incoming values are treated as no-ops to preserve manual edits.
     */
    private function isMoreSubstantial(string $incoming, ?string $existing): bool
    {
        $existingTrimmed = trim((string) $existing);
        if ($existingTrimmed === '') {
            return true;
        }

        return mb_strlen($incoming) > mb_strlen($existingTrimmed);
    }

    /**
     * Upsert a marketing list subscription for the given affiliate + email.
     *
     * Creates an EmailSubscription row (list_key='marketing') if none exists, or
     * reactivates an existing one via markSubscribed(). The EmailSubscription
     * `saved` hook syncs the marketing_opt_in_cached flag on the matching
     * Customer row, so callers should run captureContact() first.
     *
     * Race handling: the unique index is (professional_id, list_key, email_lc).
     * If two concurrent captures for the same email both miss the SELECT and
     * both try to INSERT, Postgres raises 23505 on the loser. We re-fetch the
     * row the winner just created and re-apply the loser's intended state to
     * it (so a late opt-in doesn't lose to an earlier unsubscribe row). The
     * whole upsert runs inside a transaction so the re-apply is atomic.
     *
     * @param  array{ip_hash?:?string, user_agent?:?string}  $consentMeta
     */
    public function captureMarketingSubscription(
        string $professionalId,
        string $email,
        ?string $fullName,
        string $consentSource,
        array $consentMeta = []
    ): void {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $consent = [
            'source' => $consentSource,
            'ip_hash' => $consentMeta['ip_hash'] ?? null,
            'user_agent' => $consentMeta['user_agent'] ?? null,
        ];

        try {
            $this->upsertMarketingSubscription($professionalId, $email, $fullName, $consent);
        } catch (UniqueConstraintViolationException $e) {
            // Another request beat us to the INSERT. Re-fetch and make sure the
            // surviving row reflects the state we wanted (subscribed).
            try {
                $this->reconcileRacedSubscription($professionalId, $email, $fullName, $consent);
            } catch (Throwable $reconcileError) {
                report($reconcileError);
                Log::warning('Marketing subscription reconcile after race failed', [
                    'professional_id' => $professionalId,
                    'message' => $reconcileError->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('Marketing subscription capture failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Do the SELECT-then-INSERT/UPDATE inside a transaction so our re-fetch
     * on collision sees a consistent view.
     *
     * @param  array{source:?string, ip_hash:?string, user_agent:?string}  $consent
     */
    private function upsertMarketingSubscription(
        string $professionalId,
        string $email,
        ?string $fullName,
        array $consent,
    ): void {
        DB::connection('pgsql')->transaction(function () use ($professionalId, $email, $fullName, $consent) {
            $sub = EmailSubscription::query()
                ->where('professional_id', $professionalId)
                ->where('list_key', 'marketing')
                ->where('email_lc', $email)
                ->first();

            if (! $sub) {
                $sub = new EmailSubscription([
                    'professional_id' => $professionalId,
                    'list_key' => 'marketing',
                    'email' => $email,
                    'email_lc' => $email,
                    'full_name' => $fullName,
                    'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                ]);
            } else {
                if ($fullName !== null && $fullName !== '') {
                    $sub->full_name = $fullName;
                }
                if (! $sub->unsubscribe_token) {
                    $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                }
            }

            $sub->markSubscribed($consent);
            $sub->save();
        });
    }

    /**
     * Re-fetch the row the race winner created and ensure it reflects the
     * subscribed state we were trying to write. No-ops if the winner already
     * wrote the same state; otherwise updates in place.
     *
     * @param  array{source:?string, ip_hash:?string, user_agent:?string}  $consent
     */
    private function reconcileRacedSubscription(
        string $professionalId,
        string $email,
        ?string $fullName,
        array $consent,
    ): void {
        DB::connection('pgsql')->transaction(function () use ($professionalId, $email, $fullName, $consent) {
            $sub = EmailSubscription::query()
                ->where('professional_id', $professionalId)
                ->where('list_key', 'marketing')
                ->where('email_lc', $email)
                ->first();

            if (! $sub) {
                // Vanishingly unlikely — the row that collided with us somehow
                // disappeared before we could fetch it. Nothing to reconcile.
                return;
            }

            $needsUpdate = $sub->status !== 'subscribed';

            if ($fullName !== null && $fullName !== '' && $sub->full_name !== $fullName) {
                $sub->full_name = $fullName;
                $needsUpdate = true;
            }

            if (! $needsUpdate) {
                return;
            }

            $sub->markSubscribed($consent);
            $sub->save();
        });
    }

    /**
     * Save the customer, handling the per-affiliate phone uniqueness index.
     * If the incoming phone collides with another contact, keep the original
     * phone and proceed — we never want a phone collision to drop the whole
     * upsert, since email is the primary dedup key.
     */
    private function savePreservingPhoneOnCollision(Customer $customer, ?string $phone): void
    {
        if ($phone === null) {
            $customer->save();

            return;
        }

        try {
            $customer->phone = $phone;
            $customer->save();
        } catch (UniqueConstraintViolationException $e) {
            $customer->phone = $customer->getOriginal('phone');
            $customer->save();
        }
    }
}
