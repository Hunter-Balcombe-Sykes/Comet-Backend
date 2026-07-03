<?php

namespace App\Jobs\Design;

use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignPresetResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Rebuilds a user's design-kit preset contributions from their active
// integration connections, then rolls the sitepage/email caches if anything
// changed. Dispatched by IntegrationConnectionObserver on connect / refresh /
// disconnect. ShouldBeUnique per user so a burst of connection writes (e.g.
// Google Business seeding several child rows) coalesces to a single rebuild.
class ResolveDesignPresetsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    public int $timeout = 30;

    // Coalesce window: duplicate dispatches for the same user while one is
    // queued/running are dropped. Exceeds $timeout so a slow rebuild can't
    // release the lock early and let a duplicate through.
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function __construct(public readonly string $userId)
    {
        $this->onQueue('default');
    }

    public function handle(DesignPresetResolver $resolver): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            $this->fail(new \RuntimeException("User {$this->userId} not found."));

            return;
        }

        $changed = $resolver->resolveForUser($user);

        // The preset layer IS part of the public profile payload, so a change
        // must roll the profile cache key (+ email brand + edge). Touching the
        // site row fires SiteObserver::saved, which runs the whole existing purge
        // chain — no need to reproduce it here. Only touch when something moved.
        if ($changed) {
            $user->site()->first()?->touch();
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('design.presets.resolve.failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
