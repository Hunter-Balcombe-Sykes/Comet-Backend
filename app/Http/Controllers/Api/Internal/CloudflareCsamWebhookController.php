<?php

namespace App\Http\Controllers\Api\Internal;

use App\DTOs\Moderation\CloudflareCsamMatchDto;
use App\Http\Controllers\Controller;
use App\Services\Moderation\CsamMatchHandlerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CloudflareCsamWebhookController extends Controller
{
    public function handle(Request $request, CsamMatchHandlerService $handler): JsonResponse
    {
        $payload = $request->json()->all();

        try {
            $dto    = CloudflareCsamMatchDto::fromArray($payload);
            $result = $handler->handle($dto);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'MEDIA_NOT_FOUND:')) {
                return response()->json(['error' => 'MEDIA_NOT_FOUND'], 404);
            }
            throw $e;
        }

        return response()->json([
            'case_id'       => $result['case_id'],
            'decision_id'   => $result['decision_id'],
            'quarantine_id' => $result['quarantine_id'],
        ], 200);
    }
}
