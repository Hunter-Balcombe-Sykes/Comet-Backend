<?php

namespace App\Http\Controllers\Api\Professional\Affiliate;

use App\Enums\AccountType;
use App\Exceptions\InvalidAccountTypeTransition;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountTypeTransitionService;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OpenInviteController extends ApiController
{
    use ResolveCurrentProfessional;

    public function claim(
        Request $request,
        string $handle,
        BrandAffiliateInviteService $inviteService,
        BrandPartnerLinkService $brandPartnerLinks,
        AccountTypeDefaultsService $accountTypeDefaultsService,
        AccountTypeTransitionService $transitionService
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        $handle = strtolower(trim($handle));
        // Dual-read: match brands during the §28.1 window where account_type may still be null.
        $brandProfessional = Professional::query()
            ->where('handle_lc', $handle)
            ->where(function ($q): void {
                $q->where('account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')->where('professional_type', 'brand');
                    });
            })
            ->with('brandProfile')
            ->first();

        if (! $brandProfessional) {
            return $this->error('Brand not found.', 404);
        }

        try {
            $invite = $inviteService->claimOpenInvite($brandProfessional, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        // §28.12: flip account_type to partner (+ KV sync + event) after the
        // invite service has already created the BrandPartnerLink. The transition
        // service does NOT create the link again — it only handles account_type.
        // InvalidAccountTypeTransition surfaces as 422 — this guards against
        // brand-typed pros somehow reaching this endpoint (a 500 from the global
        // handler would be a worse UX).
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }

        return $this->success([
            'invite' => [
                'id' => $invite->id,
                'status' => $invite->status,
                'invite_type' => $invite->invite_type,
                'brand_professional_id' => $invite->brand_professional_id,
                'claimed_professional_id' => $invite->claimed_professional_id,
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            ],
        ]);
    }

    private function syncSiteBrandPartnerSettings(
        Site $site,
        BrandPartnerLinkService $brandPartnerLinks,
        string $affiliateProfessionalId
    ): void {
        $links = $brandPartnerLinks->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primaryLink = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primaryLink) {
            $brandPartner['professional_id'] = (string) $primaryLink->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($link): bool => (int) $link->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($link): array => [
                'professional_id' => (string) $link->brand_professional_id,
            ])
            ->values()
            ->all();

        $site->settings = $settings;
        $site->save();
    }
}
