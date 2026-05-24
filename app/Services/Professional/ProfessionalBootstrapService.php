<?php

namespace App\Services\Professional;

use App\Enums\AccountType;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Bootstrap a new or existing professional from a validated request. Runs the
// create-or-update under a single DB transaction. Disabled-status check is
// performed BEFORE the transaction so the 403 short-circuits cleanly — the
// previous in-closure `return $this->error(...)` returned a JsonResponse object
// through the transaction, which the outer success() re-encoded as '{}'.
class ProfessionalBootstrapService
{
    public function __construct(
        private readonly SiteProvisioningService $siteProvisioning,
        private readonly ProfessionalCacheService $cache,
    ) {}

    /**
     * Bootstrap or update a professional + their site.
     *
     * @param  array<string, mixed>  $data  Validated payload from BootstrapRequest
     * @return array{professional: User, site: Site, created: bool}
     *
     * @throws RuntimeException with one of: 'ACCOUNT_DISABLED', 'EMAIL_ALREADY_REGISTERED'.
     *                          Other exceptions propagate.
     */
    public function bootstrap(string $uid, array $data): array
    {
        $existing = User::query()->where('auth_user_id', $uid)->first();
        if ($existing && in_array($existing->status, ['disabled', 'suspended', 'pending_deletion'], true)) {
            throw new RuntimeException('ACCOUNT_DISABLED');
        }

        return DB::transaction(function () use ($uid, $data, $existing) {
            $createdProfessional = false;
            $professional = $existing;

            if (! $professional) {
                $this->guardAgainstEmailReuseByDifferentAuthUser((string) ($data['primary_email'] ?? ''), $uid);

                $createdProfessional = true;
                $professional = new User([
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
                $professional->fill([
                    'handle' => $data['handle'],
                    'display_name' => $data['display_name'],
                    'primary_email' => $data['primary_email'],
                    'phone' => array_key_exists('phone', $data) ? $data['phone'] : $professional->phone,
                    'first_name' => $data['first_name'] ?? $professional->first_name,
                    'last_name' => $data['last_name'] ?? $professional->last_name,
                    'country_code' => $data['country_code'] ?? $professional->country_code,
                    'timezone' => $data['timezone'] ?? $professional->timezone,
                    'handle_lc' => $data['handle_lc'],
                ]);
            }

            $professional->save();

            $this->ensureSidestUpdatesSubscription($professional->primary_email);

            $site = Site::query()->where('professional_id', $professional->id)->first();
            if (! $site) {
                $base = $this->siteProvisioning->subdomainBaseFromHandle($data['handle']);
                $site = $this->siteProvisioning->createSiteWithRetry($professional->id, $base);
            }

            $this->cache->invalidateProfessional($professional);

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

            return [
                'professional' => $professional->fresh(),
                'site' => $site->fresh(),
                'created' => $createdProfessional,
            ];
        });
    }

    private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
    {
        $emailLc = strtolower(trim($email));
        if ($emailLc === '') {
            return;
        }

        $existingByEmail = User::query()
            ->whereRaw('lower(primary_email) = ?', [$emailLc])
            ->where('auth_user_id', '!=', $uid)
            ->exists();

        if ($existingByEmail) {
            throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
        }
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
            return;
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

    private function createWelcomeNotification(User $professional): void
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
}
