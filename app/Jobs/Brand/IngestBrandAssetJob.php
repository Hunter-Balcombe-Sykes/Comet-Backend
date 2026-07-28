<?php

namespace App\Jobs\Brand;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Brand\BrandAssetPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fetch one brand asset and attach it to a connection (plan §12).
 *
 * On the images lane, not scraping: this decodes and re-encodes an image, so
 * it belongs with the work sized for that (the RV-12 stall precedent is why
 * lane choice is stated rather than defaulted).
 *
 * Unique per (connection, role) for the job's lifetime: a re-scan of the same
 * storefront must not queue a second download of the same logo.
 */
class IngestBrandAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $userId,
        public readonly string $connectionId,
        public readonly string $role,
        public readonly string $sourceUrl,
        public readonly string $attribution = '',
    ) {
        $this->onQueue(config('partna.queues.images', 'images'));
    }

    public function uniqueId(): string
    {
        return $this->connectionId.':'.$this->role;
    }

    /**
     * No capability gate here, deliberately. `shop` is an everyone class in the
     * routing gate matrix, and Gate 1 — the sanctioned AccountCapabilities read
     * — already ran in PlacementPolicy before the connection this asset hangs
     * off was ever written. Re-deciding it here would be a second authority
     * that can disagree with the first, which is exactly the failure the
     * "gate once, in the policy" rule exists to prevent.
     *
     * What DOES have to be re-checked is liveness: dispatch and run are
     * separated by a queue, and a user who disconnected the store in between
     * must not end up with owned bytes for it. Hard-delete on disconnect is
     * easier to honour by never creating the asset.
     */
    public function handle(BrandAssetPipeline $pipeline): void
    {
        $user = User::find($this->userId);

        // A user mid-deletion gets no new owned bytes — the erasure sweep has
        // already decided what it is collecting.
        if ($user === null || $user->isPendingDeletion()) {
            return;
        }

        $live = IntegrationConnection::query()
            ->where('id', $this->connectionId)
            ->where('user_id', $this->userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $live) {
            return;
        }

        $pipeline->ingest($this->userId, $this->connectionId, $this->role, $this->sourceUrl, $this->attribution);
    }
}
