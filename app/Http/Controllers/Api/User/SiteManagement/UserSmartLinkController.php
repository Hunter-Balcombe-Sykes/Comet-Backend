<?php

namespace App\Http\Controllers\Api\User\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Tolerant shim (Phase 1 of the SmartLinks removal).
 *
 * SmartLinks is removed — superseded by Platform Integrations. These 7
 * endpoints stay registered but return empty success shapes so the live
 * dashboard never 404/500s during the frontend cutover. They depend on no
 * deleted code and never touch the (dropped) site.smart_links table.
 *
 * Phase 2 deletes this controller, its routes, and the shim test once the
 * frontend stops calling /smart-links. Deploy order: BE Phase 1 → FE → BE Phase 2.
 *
 * The `{smartLink}` route segment is typed as a plain string (not the deleted
 * SmartLink model) so the routes resolve without model binding.
 */
class UserSmartLinkController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success(['smart_links' => []]);
    }

    public function preview(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function store(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function update(string $smartLink): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function reorder(): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function refresh(string $smartLink): JsonResponse
    {
        return $this->success(['smart_link' => null]);
    }

    public function destroy(string $smartLink): Response
    {
        return response()->noContent();
    }
}
