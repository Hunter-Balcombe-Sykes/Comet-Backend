<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Exceptions\Gdpr\DataExportInProgressException;
use App\Exceptions\Gdpr\NoRecipientEmailException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\RequestStaffDataExportRequest;
use App\Models\Core\Professional\User;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Professional\DataExport\DataExportService;
use Illuminate\Http\JsonResponse;

// V2: Staff-triggered data export. Same DataExportService as self-service —
// only difference is the recipient resolution path. send_to=staff requires
// admin role (data exfiltration to a Partna inbox). Default send_to=professional
// (the safer mode) is allowed for any staff role.
class StaffDataExportController extends ApiController
{
    public function __construct(
        private readonly DataExportService $exportService,
    ) {}

    public function store(
        RequestStaffDataExportRequest $request,
        User $professional,
    ): JsonResponse {
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');

        // send_to is validated by RequestStaffDataExportRequest; default is 'professional'.
        $sendTo = (string) $request->query('send_to', 'professional');

        if ($sendTo === 'staff' && ! $staff->isAdmin()) {
            return $this->error('Only admin staff can receive exports directly.', 403);
        }

        try {
            $audit = $this->exportService->dispatch(
                professional: $professional,
                triggeredBy: 'staff',
                staffId: $staff->id,
                sendTo: $sendTo,
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
            'send_to' => $audit->send_to,
            'professional' => [
                'id' => $professional->id,
                'handle' => $professional->handle,
            ],
        ], 202);
    }
}
