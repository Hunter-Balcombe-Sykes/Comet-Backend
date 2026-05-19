<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Exceptions\CommissionExportInProgressException;
use App\Exceptions\NoRecipientEmailException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Stripe\RequestCommissionExportRequest;
use App\Http\Resources\Professional\CommissionExportAuditResource;
use App\Models\Commerce\CommissionExportAudit;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionExportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POST /stripe/exports/transactions — queues an async commission transactions export.
 * GET  /stripe/exports/commission/{exportId} — polls the audit row for status.
 *
 * Role is server-inferred from the authenticated professional's type; the client
 * never supplies it (prevents cross-role enumeration via the request body).
 */
class CommissionExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly CommissionExportService $service) {}

    public function store(RequestCommissionExportRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = $this->inferRole($pro);

        // Skeleton policy check — mirrors StripeConnectController::export.
        // Uses CommissionPayout (not CommissionMovement) because viewOwnPayouts
        // is registered on CommissionPayout and expects that model type.
        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton);

        try {
            $audit = $this->service->dispatch(
                professional: $pro,
                role: $role,
                format: $request->validated('format'),
                filters: $request->onlyFilters(),
            );
        } catch (NoRecipientEmailException $e) {
            return response()->json(['message' => 'No recipient email on file. Add a primary email first.'], 422);
        } catch (CommissionExportInProgressException $e) {
            return response()->json([
                'message' => 'An export is already in progress for this account.',
                'export_id' => $e->existing->id,
                'status' => $e->existing->status,
            ], 409);
        }

        return response()->json([
            'export_id' => $audit->id,
            'status' => $audit->status,
            'payouts_total' => $audit->payouts_total,
            'chunk_size' => $audit->chunk_size,
            'recipient_email' => $audit->recipient_email,
            'message' => sprintf(
                "Your commission report is being prepared. We'll email %s when it's ready — the link will be valid for %d days.",
                $audit->recipient_email,
                (int) config('partna.exports.commission.signed_url_ttl_days'),
            ),
        ], 202, [
            'Location' => route('professional.stripe.exports.commission.show', ['exportId' => $audit->id]),
        ]);
    }

    public function show(Request $request, string $exportId): CommissionExportAuditResource
    {
        $pro = $request->attributes->get('professional');

        $audit = CommissionExportAudit::query()
            ->where('id', $exportId)
            ->where('professional_id', $pro->id)
            ->first();

        // 404 not 403 — cross-tenant per CLAUDE.md: never reveal whether a resource exists
        abort_if(! $audit, 404);

        return new CommissionExportAuditResource($audit);
    }

    /**
     * Infer the export role from the authenticated professional's type.
     * Brands request brand-side data; everyone else (influencer, professional) is affiliate.
     * Mirrors the role-determination pattern in StripeConnectController::export.
     */
    private function inferRole(Professional $pro): string
    {
        return $pro->isBrand() ? 'brand' : 'affiliate';
    }
}
