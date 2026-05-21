<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\AccountType;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Waitlist\WaitlistSignup;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

// V2: Account signup/update. Creates professional + site, applies individual defaults.
class BootstrapController extends ApiController
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    public function bootstrap(
        BootstrapRequest $request,
    ) {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->isWaitlistModeEnabled() && ! $this->hasExistingProfessional($uid)) {
            return $this->error(
                'New account creation is currently waitlist-only. Please join the waitlist.',
                403,
                ['code' => 'WAITLIST_ONLY']
            );
        }

        // §28.14 — Individual waitlist diversion (CFG-1). Runs BEFORE validation.
        if (
            (bool) config('partna.individual_waitlist_enabled', false)
            && ! $this->hasExistingProfessional($uid)
        ) {
            $emailLc = strtolower(trim((string) $request->input('primary_email', '')));
            if ($emailLc !== '') {
                WaitlistSignup::query()->updateOrCreate(
                    ['email_lc' => $emailLc],
                    [
                        'email' => $emailLc,
                        'name' => trim(((string) $request->input('first_name', '')).' '.((string) $request->input('last_name', ''))) ?: null,
                        'applicant_type' => 'individual',
                        'consent_source' => 'individual_waitlist_divert',
                        'last_submitted_at' => now(),
                    ]
                );
            }

            return $this->error(
                'New individual signups are temporarily on a waitlist. We\'ll be in touch.',
                403,
                ['code' => 'INDIVIDUAL_WAITLIST']
            );
        }

        $data = $request->validated();

        try {
            $result = DB::transaction(function () use ($uid, $data) {
                $createdProfessional = false;

                $professional = Professional::query()->where('auth_user_id', $uid)->first();

                if (! $professional) {
                    // Defensive: a different Supabase user already owns this email.
                    $emailLc = strtolower(trim((string) $data['primary_email']));
                    if ($emailLc !== '') {
                        $existingByEmail = Professional::query()
                            ->whereRaw('lower(primary_email) = ?', [$emailLc])
                            ->where('auth_user_id', '!=', $uid)
                            ->exists();

                        if ($existingByEmail) {
                            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
                        }
                    }

                    $createdProfessional = true;
                    $professional = new Professional([
                        'handle' => $data['handle'],
                        'display_name' => $data['display_name'],
                        'bio' => null,
                        'country_code' => $data['country_code'] ?? null,
                        'timezone' => $data['timezone'] ?? null,
                        'account_type' => AccountType::Individual,
                        'status' => 'active',
                        'onboarding_step' => 0,
                        'phone' => $data['phone'] ?? null,
                        'primary_email' => $data['primary_email'],
                        'first_name' => $data['first_name'] ?? '',
                        'last_name' => $data['last_name'] ?? null,
                        'public_contact_number' => null,
                        'public_contact_email' => null,
                        'handle_lc' => $data['handle_lc'],
                    ]);
                    $professional->auth_user_id = $uid;
                } else {

                    if (in_array($professional->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
                        return $this->error('Account is disabled. Contact support.', 403);
                    }

                    $fill = [
                        'handle' => $data['handle'],
                        'display_name' => $data['display_name'],
                        'primary_email' => $data['primary_email'],
                        'phone' => $data['phone'] ?? $professional->phone,
                        'first_name' => $data['first_name'] ?? $professional->first_name,
                        'last_name' => $data['last_name'] ?? $professional->last_name,
                        'country_code' => $data['country_code'] ?? $professional->country_code,
                        'timezone' => $data['timezone'] ?? $professional->timezone,
                        'handle_lc' => $data['handle_lc'],
                    ];

                    if (array_key_exists('phone', $data)) {
                        $fill['phone'] = $data['phone'];
                    }

                    $professional->fill($fill);
                }
                $professional->save();

                // Add to Partna updates list once (global list).
                $this->ensureSidestUpdatesSubscription($professional->primary_email);

                $site = Site::query()->where('professional_id', $professional->id)->first();

                if (! $site) {
                    $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);
                    $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
                }


                app(ProfessionalCacheService::class)->invalidateProfessional($professional);

                if ($createdProfessional) {
                    $this->createWelcomeNotification($professional);
                }

                return [
                    'professional' => new ProfessionalDashboardResource($professional->fresh()),
                    'site' => $site->fresh(),
                ];
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'EMAIL_ALREADY_REGISTERED') {
                Log::info('Bootstrap rejected: email already registered to another auth user', [
                    'uid' => $uid,
                    'email' => $data['primary_email'] ?? null,
                ]);

                return $this->error(
                    'This email is already associated with a different account. Sign in with your original method, or contact support to link accounts.',
                    409,
                    ['code' => 'EMAIL_ALREADY_REGISTERED']
                );
            }

            Log::error('Bootstrap transaction failed', [
                'error' => $e->getMessage(),
                'uid' => $uid,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Bootstrap transaction failed', [
                'error' => $e->getMessage(),
                'uid' => $uid,
            ]);
            throw $e;
        }

        return $this->success($result);
    }

    private function ensureSidestUpdatesSubscription(?string $email): void
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if ($email === '') {
            return;
        }

        $listKey = 'sidest_updates';

        $existing = EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $listKey)
            ->where('email_lc', $email)
            ->first();

        if ($existing) {
            return; // keep whatever status they chose
        }

        $sub = new EmailSubscription([
            'professional_id' => null,
            'list_key' => $listKey,
            'email' => $email,
            'email_lc' => $email,
            'full_name' => null,
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
        ]);

        $sub->markSubscribed(['source' => 'bootstrap']);
        $sub->save();
    }

    private function createWelcomeNotification(Professional $professional): void
    {
        Notification::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'type' => 'Info',
                'title' => 'Welcome to Partna',
            ],
            [
                'body' => 'Your account is ready. Complete your profile and start building your professional page from your dashboard.',
                'cta_url' => null,
                'severity' => 'info',
                'starts_at' => now(),
                'ends_at' => null,
            ]
        );
    }

    private function isWaitlistModeEnabled(): bool
    {
        return (bool) config('partna.waitlist.enabled', false);
    }

    private function hasExistingProfessional(string $uid): bool
    {
        return Professional::query()
            ->where('auth_user_id', $uid)
            ->exists();
    }
}
