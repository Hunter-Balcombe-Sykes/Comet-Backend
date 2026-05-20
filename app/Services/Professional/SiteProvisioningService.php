<?php

namespace App\Services\Professional;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

// V2: Provisions new professional sites with unique subdomains and seeds free-tier subscriptions.
class SiteProvisioningService
{
    public function createSiteWithRetry(string $professionalId, string $base): Site
    {
        $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
        $base = strtolower($base);
        $baseIsReserved = in_array($base, $reserved, true);

        if ($baseIsReserved) {
            for ($i = 1; $i <= 20; $i++) {
                $candidate = $this->buildCandidate($base, (string) $i);
                $site = $this->tryCreateSite($professionalId, $candidate);
                if ($site) {
                    return $site;
                }
            }
        } else {
            for ($i = 0; $i < 20; $i++) {
                $suffix = $i === 0 ? null : (string) $i;
                $candidate = $this->buildCandidate($base, $suffix);
                $site = $this->tryCreateSite($professionalId, $candidate);
                if ($site) {
                    return $site;
                }
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $rand = Str::lower(Str::random(6));
            $candidate = $this->buildCandidate($base, $rand);
            $site = $this->tryCreateSite($professionalId, $candidate);
            if ($site) {
                return $site;
            }
        }

        throw new RuntimeException('Could not allocate a unique subdomain.');
    }

    public function subdomainBaseFromHandle(string $handle): string
    {
        $v = mb_strtolower(trim($handle));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        $v = trim($v, '-');

        if ($v === '') {
            $v = 'user-'.substr(Str::uuid()->toString(), 0, 8);
        }

        return $v;
    }

    public function ensureFreeSubscription(Professional $professional): void
    {
        // Brands must pay for the 'brands' plan — no free tier
        if ($professional->isBrand()) {
            return;
        }

        $professional->load('subscription');

        if ($professional->subscription && $professional->subscription->ended_at === null) {
            return;
        }

        $freePlan = Plan::where('plan_key', 'free')->where('is_active', true)->first();
        if (! $freePlan) {
            \Illuminate\Support\Facades\Log::warning('No active free plan found – skipping subscription seed', [
                'professional_id' => $professional->id,
            ]);

            return;
        }

        Subscription::create([
            'id' => Str::uuid()->toString(),
            'professional_id' => $professional->id,
            'plan_id' => $freePlan->id,
            'provider' => 'internal',
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => null,
            'cancel_at_period_end' => false,
        ]);
    }

    private function buildCandidate(string $base, ?string $suffix): string
    {
        if ($suffix === null) {
            return $base;
        }

        $base = $this->limitSubdomainBase($base, '-'.$suffix);

        return $base.'-'.$suffix;
    }

    private function limitSubdomainBase(string $base, string $suffixIncludingHyphen): string
    {
        $max = 63 - strlen($suffixIncludingHyphen);
        if ($max < 1) {
            return substr($base, 0, 1);
        }

        return substr($base, 0, $max);
    }

    private function tryCreateSite(string $professionalId, string $candidate): ?Site
    {
        try {
            $site = new Site([
                'subdomain' => $candidate,
                'theme_id' => null,
                'is_published' => false,
                'settings' => [],
            ]);

            $site->professional_id = $professionalId;
            $site->save();

            return $site;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
