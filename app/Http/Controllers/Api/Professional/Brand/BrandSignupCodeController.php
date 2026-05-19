<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\BrandSignupCodeAuditEntry;
use App\Services\Professional\Brand\BrandSignupCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dashboard endpoints for brand signup code management.
// All routes are gated by `brand.only` middleware — non-brand accounts never reach these methods.
class BrandSignupCodeController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandSignupCodeService $codeService,
    ) {}

    /**
     * GET /api/professional/brand/signup-code
     * Returns the current code, its status, and dashboard aggregate counts.
     *
     * @return array{code: string|null, active: bool, rotated_at: string|null, claims_30d_count: int, failed_attempts_7d_count: int, last_rotation: string|null}
     */
    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $profile = $this->requireBrandProfile($professional->id);

        return $this->success($this->buildResponse($profile));
    }

    /**
     * POST /api/professional/brand/signup-code/rotate
     * Generates a new code immediately — the old code stops working at once.
     */
    public function rotate(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $profile = $this->requireBrandProfile($professional->id);

        $this->codeService->rotate($profile);

        return $this->success($this->buildResponse($profile->refresh()));
    }

    /**
     * POST /api/professional/brand/signup-code/deactivate
     * Deactivates the code without rotating it.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $profile = $this->requireBrandProfile($professional->id);

        $this->codeService->deactivate($profile);

        return $this->success($this->buildResponse($profile->refresh()));
    }

    /**
     * POST /api/professional/brand/signup-code/reactivate
     * Re-enables a deactivated code.
     */
    public function reactivate(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $profile = $this->requireBrandProfile($professional->id);

        $this->codeService->reactivate($profile);

        return $this->success($this->buildResponse($profile->refresh()));
    }

    /**
     * Fetch or 404 the brand profile for the given professional.
     */
    private function requireBrandProfile(string $professionalId): BrandProfile
    {
        $profile = BrandProfile::query()->where('professional_id', $professionalId)->first();

        if (! $profile) {
            abort(404, 'Brand profile not found.');
        }

        return $profile;
    }

    /**
     * Shared response shape for all four endpoints.
     *
     * @return array{code: string|null, active: bool, rotated_at: string|null, claims_30d_count: int, failed_attempts_7d_count: int, last_rotation: string|null}
     */
    private function buildResponse(BrandProfile $profile): array
    {
        $claims30d = BrandSignupCodeAuditEntry::query()
            ->where('brand_profile_id', $profile->id)
            ->where('event', 'claimed')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $failedAttempts7d = BrandSignupCodeAuditEntry::query()
            ->where('brand_profile_id', $profile->id)
            ->where('event', 'failed_claim')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'code' => $profile->signup_code,
            'active' => (bool) $profile->signup_code_active,
            'rotated_at' => $profile->signup_code_rotated_at?->toIso8601String(),
            'claims_30d_count' => $claims30d,
            'failed_attempts_7d_count' => $failedAttempts7d,
            'last_rotation' => $profile->signup_code_rotated_at?->toIso8601String(),
        ];
    }
}
