<?php

namespace App\Observers\Core;

use App\Models\Core\Site\SmartLink;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use Illuminate\Support\Facades\Log;

// Busts caches on any smart-link write — dashboard CRUD AND the refresh cron's
// snapshot updates both flow through here. Mirrors ServiceObserver: invalidate
// the per-user cache and touch the parent site so SiteObserver fires
// CloudflareCachePurgeJob and the Redis public-profile key rolls forward.
class SmartLinkObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly UserCacheService $userCache,
    ) {}

    public function saved(SmartLink $link): void
    {
        $this->runHooks($link);
    }

    public function deleted(SmartLink $link): void
    {
        $this->runHooks($link);
    }

    public function restored(SmartLink $link): void
    {
        $this->runHooks($link);
    }

    private function runHooks(SmartLink $link): void
    {
        try {
            $pro = User::query()->with('site')->find($link->user_id);
            if ($pro) {
                // bustSite: false — touch() always follows below, which fires
                // SiteObserver → invalidateSite(). Passing true would double-bust
                // ~29 Redis keys on every smart-link write (mirrors ServiceObserver::bust()).
                $this->userCache->invalidateUser($pro, bustSite: false);
                // Bump sites.updated_at → SiteObserver dispatches
                // CloudflareCachePurgeJob and the public-profile Redis key
                // (public.profile:{handle}:{updated_at_ts}) rolls forward.
                $pro->site?->touch();
            }
        } catch (\Throwable $e) {
            Log::warning('SmartLinkObserver hook failed', [
                'smart_link_id' => $link->id,
                'user_id' => $link->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
