<?php

namespace App\Http\Controllers\Api\Platforms\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

// Shared single-tenant test-mode selection storage for the platform
// controllers. Each platform stores its selection blob under one cache key
// (selectionKey()); this trait centralises the read/write/clear plus the
// standard GET /selection and DELETE handlers so the per-platform controllers
// stop re-implementing the same three lines. Controllers that keep extra keys
// (Fresha's url, Shopify's url+discount) override forget() and clear those
// alongside; controllers with more than one selection (Apple music/podcast)
// don't use this trait.
//
// Mixed into ApiController subclasses, so $this->success() is available.
trait ManagesPlatformSelection
{
    // Days a test-mode selection lives in the cache.
    protected int $selectionTtlDays = 30;

    // The cache key this platform stores its selection under.
    abstract protected function selectionKey(): string;

    // Read the saved selection (null when nothing is stored). Pass an explicit
    // key for platforms that keep more than one.
    protected function readSelection(?string $key = null): mixed
    {
        return Cache::get($key ?? $this->selectionKey());
    }

    // Persist a selection blob under the platform's key (or an explicit one).
    protected function writeSelection(array $data, ?string $key = null): void
    {
        Cache::put($key ?? $this->selectionKey(), $data, now()->addDays($this->selectionTtlDays));
    }

    // Forget one stored key (the platform's, or an explicit one).
    protected function clearSelection(?string $key = null): void
    {
        Cache::forget($key ?? $this->selectionKey());
    }

    // GET /api/platforms/<x>/selection
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => $this->readSelection()]);
    }

    // DELETE /api/platforms/<x>
    public function forget(): JsonResponse
    {
        $this->clearSelection();

        return $this->success(['selection' => null]);
    }
}
