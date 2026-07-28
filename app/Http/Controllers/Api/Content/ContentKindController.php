<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Content\ContentKindResource;
use App\Services\Content\KindRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/content/kinds` — the registry the dashboard's LibraryView builds
 * its columns from (plan §16).
 *
 * The wire carries per-kind `pinnable` / `editable` / `orderable`. Those flags
 * are the mechanism that makes Reviews show/hide-only: the server decides,
 * once, from the SourceProfile that governs the kind, and the client obeys. A
 * client that re-derived them from the kind name would eventually render an
 * edit form for a review — which is not a UI bug, it is forgery of a third
 * party's words.
 *
 * The registry is a compile-time declaration with no tenant data in it, so it
 * is the same answer for every account and is cached at the edge.
 */
class ContentKindController extends ApiController
{
    /** How long a client may hold the registry. It changes on deploy, not on use. */
    private const CACHE_SECONDS = 900;

    public function index(Request $request): JsonResponse
    {
        return $this->success(['kinds' => ContentKindResource::collection(KindRegistry::all())])
            ->header('Cache-Control', 'private, max-age='.self::CACHE_SECONDS);
    }
}
