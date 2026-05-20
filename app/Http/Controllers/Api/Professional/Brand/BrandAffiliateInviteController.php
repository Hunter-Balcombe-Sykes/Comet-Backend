<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Enums\AccountType;
use App\Exceptions\InvalidAccountTypeTransition;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ParsesBrandAffiliateInviteCsv;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountTypeTransitionService;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\Brand\BrandAffiliateInviteService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// V2: Brand creates/manages affiliate invitations (single, bulk, CSV). Affiliates claim or decline via token. Core V2 onboarding flow.
class BrandAffiliateInviteController extends ApiController
{
    use NormalizesPerPage;
    use ParsesBrandAffiliateInviteCsv;
    use ResolveCurrentProfessional;
    use ReturnsPaginatedResponse;

    private const BULK_MAX_ROWS = 500;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can view affiliate invites.', 403);
        }

        $perPage = $this->normalizePerPage($request, 25, 100);

        $page = $professional->brandAffiliateInvites()
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        // Resolve per-invite recipient state for the pending rows: the single-brand
        // cap means a pending invite to someone already partnered with a different
        // brand will 422 on acceptance. Surface this so the brand UI can show a
        // "partnered with another brand" badge instead of leaving the invite
        // looking actionable. Scoped to the current page so cost is O(per_page),
        // not O(total invites).
        $partneredEmails = $this->resolveEmailsPartneredWithOtherBrands(
            (string) $professional->id,
            $page->getCollection()->pluck('email_lc')->filter()->unique()->values()->all(),
        );

        $invites = $page->getCollection()->map(function ($invite) use ($partneredEmails): array {
            $effectiveStatus = $invite->status === 'pending' && $invite->expires_at && $invite->expires_at->isPast()
                ? 'expired'
                : $invite->status;

            $emailLc = is_string($invite->email_lc) ? $invite->email_lc : null;
            $partneredElsewhere = $effectiveStatus === 'pending'
                && $emailLc !== null
                && isset($partneredEmails[$emailLc]);

            return [
                'id' => $invite->id,
                'status' => $effectiveStatus,
                'invite_type' => $invite->invite_type,
                'email' => $invite->email,
                'first_name' => $invite->first_name,
                'last_name' => $invite->last_name,
                'message' => $invite->message,
                'token' => $invite->token,
                'created_at' => optional($invite->created_at)->toIso8601String(),
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
                'recipient_partnered_elsewhere' => $partneredElsewhere,
            ];
        })->values()->all();

        $payload = $this->paginatedResponse($page, 'invites');
        $payload['invites'] = $invites;

        return $this->success($payload);
    }

    /**
     * Map of normalized email → true for recipients who currently hold a
     * BrandPartnerLink to any brand other than the one calling this endpoint.
     *
     * @param  array<int, string>  $emails
     * @return array<string, true>
     */
    private function resolveEmailsPartneredWithOtherBrands(string $brandProfessionalId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $professionals = Professional::query()
            ->where(function ($query) use ($emails) {
                $query->whereIn(DB::raw('LOWER(primary_email)'), $emails)
                    ->orWhereIn(DB::raw('LOWER(public_contact_email)'), $emails);
            })
            ->get(['id', 'primary_email', 'public_contact_email']);

        if ($professionals->isEmpty()) {
            return [];
        }

        $partneredProfessionalIds = BrandPartnerLink::query()
            ->whereIn('affiliate_professional_id', $professionals->pluck('id')->all())
            ->where('brand_professional_id', '!=', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->map(fn ($id) => (string) $id)
            ->flip()
            ->all();

        if ($partneredProfessionalIds === []) {
            return [];
        }

        $result = [];
        foreach ($professionals as $pro) {
            if (! isset($partneredProfessionalIds[(string) $pro->id])) {
                continue;
            }

            foreach ([$pro->primary_email, $pro->public_contact_email] as $email) {
                $normalized = is_string($email) ? mb_strtolower(trim($email)) : '';
                if ($normalized !== '') {
                    $result[$normalized] = true;
                }
            }
        }

        return $result;
    }

    public function store(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
            'expiration' => ['nullable', 'string', 'in:24h,7d,30d,none'],
        ]);

        try {
            $result = $inviteService->createOrRefreshInvite($professional, $data);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $invite = $result['invite'];
        $action = (string) ($result['action'] ?? 'created');

        return $this->success([
            'invite' => [
                'id' => $invite->id,
                'token' => $invite->token,
                'status' => $invite->status,
                'invite_type' => $invite->invite_type,
                'email' => $invite->email,
                'first_name' => $invite->first_name,
                'last_name' => $invite->last_name,
                'message' => $invite->message,
                'created_at' => optional($invite->created_at)->toIso8601String(),
                'accepted_at' => optional($invite->accepted_at)->toIso8601String(),
            ],
            'action' => $action,
        ], $action === 'refreshed' ? 200 : 201);
    }

    public function availability(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can check affiliate invite availability.', 403);
        }

        $data = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        return $this->success(
            $inviteService->checkRecipientAvailability(
                $professional,
                $data['email'] ?? null,
                null,
            )
        );
    }

    public function bulk(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'invites' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX_ROWS],
        ]);

        try {
            $result = $inviteService->processBulkInvites($professional, $data['invites']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result);
    }

    public function importCsv(Request $request, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can generate affiliate invites.', 403);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $rows = $this->parseInviteCsvRows($data['file'], self::BULK_MAX_ROWS);
            $result = $inviteService->processBulkInvites($professional, $rows);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result);
    }

    public function claim(
        Request $request,
        string $token,
        BrandAffiliateInviteService $inviteService,
        BrandPartnerLinkService $brandPartnerLinks,
        AccountTypeTransitionService $transitionService
    ): JsonResponse {
        $professional = $this->currentProfessional($request);
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        try {
            $claimedInvite = $inviteService->claimInvite($invite, $professional);
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
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }

        return $this->success([
            'invite' => [
                'id' => $claimedInvite->id,
                'status' => $claimedInvite->status,
                'claimed_professional_id' => $claimedInvite->claimed_professional_id,
                'accepted_at' => optional($claimedInvite->accepted_at)->toIso8601String(),
            ],
        ]);
    }

    public function decline(Request $request, string $token, BrandAffiliateInviteService $inviteService): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $invite = $inviteService->findByToken($token);

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        try {
            $declinedInvite = $inviteService->declineInvite($invite, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'invite' => [
                'id' => $declinedInvite->id,
                'status' => $declinedInvite->status,
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

    public function destroy(Request $request, string $inviteId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('Only brand accounts can delete affiliate invites.', 403);
        }

        $invite = $professional->brandAffiliateInvites()
            ->whereKey($inviteId)
            ->first();

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        // Accepted invites are the audit record of how an affiliate joined this brand.
        // Hard-deleting them would shred that paper trail (no SoftDeletes on this model).
        if ($invite->status === 'accepted') {
            return $this->error(
                'Accepted invites cannot be deleted — they are part of the connection audit trail.',
                422
            );
        }

        $invite->delete();

        return $this->success([
            'invite_id' => $inviteId,
            'deleted' => true,
        ]);
    }
}
