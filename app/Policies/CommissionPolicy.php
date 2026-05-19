<?php

namespace App\Policies;

use App\Models\Commerce\BrandAffiliateRollup;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandAccessService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for commission financial records.
 *
 * Covers: CommissionPayout, CommissionMovement.
 *
 * Read access:
 *   - The affiliate (records where affiliate_professional_id matches)
 *   - The brand owner
 *   - Brand team members with canReadBrandFinancialAnalytics capability
 *
 * Write access:
 *   - Brand owner or team member with canManageBrand capability
 *   - NOT affiliates (read-only for them)
 */
class CommissionPolicy extends BasePolicy
{
    public function __construct(private readonly BrandAccessService $brandAccess) {}

    public function view(Professional $actor, Model $record): bool|Response
    {
        $brandId = (string) ($record->brand_professional_id ?? '');
        $affiliateId = (string) ($record->affiliate_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        // Affiliate can view their own payout/ledger record
        if ($affiliateId !== '' && $actorId === $affiliateId) {
            return true;
        }

        // Brand owner can always view
        if ($actorId === $brandId) {
            return true;
        }

        // Brand team member with financial read capability
        if ($this->brandAccess->canReadBrandFinancialAnalytics($actor, $brandId)) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    /**
     * Authorizes a professional to view their own affiliate projections.
     *
     * The skeleton must carry the affiliate_professional_id of the *requested* projections.
     * Defense-in-depth: RLS on commerce.brand_affiliate_rollup also blocks cross-tenant reads,
     * but we want a clean 403 at the HTTP layer rather than an empty result.
     */
    public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool
    {
        return (string) $pro->id === (string) $skeleton->affiliate_professional_id;
    }

    /**
     * Authorizes a professional to view their own payout list — role-aware.
     *
     * Two callsites:
     *   - GET /affiliate/payouts (AffiliatePayoutsController): seeds skeleton
     *     with affiliate_professional_id only. Brand callers fail the type check.
     *   - GET /stripe/payouts?role=brand|affiliate (StripeConnectController):
     *     seeds the role-appropriate id. Type check enforces brands use role=brand
     *     and non-brands use role=affiliate, so an affiliate can't request a brand
     *     view (or vice versa) and silently get an empty 200.
     *
     * The actor must (a) match the populated id on the skeleton, and (b) be the
     * role type that matches the populated side.
     */
    public function viewOwnPayouts(Professional $pro, CommissionPayout $skeleton): bool
    {
        $actorId = (string) $pro->id;
        $isBrand = $pro->isBrand();
        $brandId = (string) ($skeleton->brand_professional_id ?? '');
        $affiliateId = (string) ($skeleton->affiliate_professional_id ?? '');

        if ($brandId !== '' && $actorId === $brandId) {
            return $isBrand;
        }

        if ($affiliateId !== '' && $actorId === $affiliateId) {
            return ! $isBrand;
        }

        return false;
    }

    /**
     * Authorizes a professional to view their own Stripe transactions list — role-aware.
     *
     * Mirrors viewOwnPayouts: a brand requesting role=brand or an affiliate requesting
     * role=affiliate is fine; cross-role calls are rejected so neither can enumerate the
     * other's Stripe data via the transactions endpoint.
     */
    public function viewOwnTransactions(Professional $pro, CommissionPayout $skeleton): bool
    {
        return $this->viewOwnPayouts($pro, $skeleton);
    }

    public function update(Professional $actor, Model $record): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        $brandId = (string) ($record->brand_professional_id ?? '');
        $actorId = (string) $actor->id;

        if ($brandId === '') {
            return $this->denyAsNotFound();
        }

        if ($actorId === $brandId) {
            return true;
        }

        if ($this->brandAccess->canManageBrand($actor, $brandId)) {
            return true;
        }

        return $this->denyAsNotFound();
    }

    public function delete(Professional $actor, Model $record): bool|Response
    {
        return $this->update($actor, $record);
    }

    /**
     * Brand can only manage their own payment method, not another professional's.
     */
    public function managePaymentMethod(Professional $actor, Professional $brand): bool
    {
        return $actor->id === $brand->id && $actor->isBrand();
    }

    /**
     * Brand commission-billing context (card on file, billing summary). Gated
     * to brand-type self-actions; mirrors managePaymentMethod.
     */
    public function manageWallet(Professional $actor, Professional $brand): bool
    {
        return $actor->id === $brand->id && $actor->isBrand();
    }

    /**
     * Every professional type can onboard with Stripe Connect Express.
     * Affiliates onboard to RECEIVE transfers; brands onboard so commission
     * charges run as direct charges on the brand's own Connect account
     * (brand = merchant of record on the customer-facing statement).
     */
    public function startConnect(Professional $actor, Professional $pro): bool
    {
        return $actor->id === $pro->id;
    }
}
