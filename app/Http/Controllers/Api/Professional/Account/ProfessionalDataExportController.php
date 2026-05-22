<?php

namespace App\Http\Controllers\Api\Professional\Account;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\RequestDataExportRequest;
use App\Models\Core\Professional\User;
use App\Services\Professional\DataExport\DataExportService;
use Illuminate\Http\JsonResponse;

// V2: Self-service data export. Thin controller — all logic in DataExportService.
// Exempt from EnforcePendingDeletionReadOnly middleware via route definition
// (a leaving professional must be able to export their data — GDPR portability).
class ProfessionalDataExportController extends ApiController
{
    public function __construct(
        private readonly DataExportService $exportService,
    ) {}

    public function store(RequestDataExportRequest $request): JsonResponse
    {
        /** @var User $professional */
        $professional = $request->attributes->get('professional');

        try {
            $audit = $this->exportService->dispatch(
                professional: $professional,
                triggeredBy: 'self',
                staffId: null,
                sendTo: 'professional',
            );
        } catch (DataExportInProgressException $e) {
            return response()->json([
                'message' => 'An export is already in progress.',
                'existing_export_id' => $e->existingExportId,
            ], 409);
        } catch (NoRecipientEmailException) {
            return $this->error('No valid recipient email on file.', 422);
        }

        return $this->success([
            'export_id' => $audit->id,
            'status' => $audit->status,
            'recipient_email' => $audit->recipient_email,
            'message' => "Your data export is being prepared. You'll receive an email at {$audit->recipient_email} within a few minutes with a download link valid for 7 days.",
        ], 202);
    }
}
