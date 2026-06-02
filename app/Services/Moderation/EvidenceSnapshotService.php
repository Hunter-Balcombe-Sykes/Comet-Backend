<?php

namespace App\Services\Moderation;

use App\Models\Core\Site\Site;
use App\Models\Moderation\Evidence;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Captures an immutable evidence row at signal time.
 *
 * The payload is the source-of-truth for what the reported content looked like
 * when the signal arrived — staff sees this snapshot, NOT the live content, when
 * deciding. Edits or deletions by the reported user after the snapshot don't
 * affect what we'll defend later.
 *
 * Per-target-type strategies fan out from capture(). Day-one: Site only.
 * SiteMedia / Block / User snapshots are added in fast-follows.
 */
class EvidenceSnapshotService
{
    public function capture(
        string $caseId,
        string $targetType,
        string $targetId,
        ?string $signalId,
    ): Evidence {
        $payload = match ($targetType) {
            'Site' => $this->snapshotSite($targetId),
            default => throw new InvalidArgumentException("Unsupported snapshot target type: {$targetType}"),
        };

        $payload['captured_at'] = Carbon::now()->toIso8601String();

        // Hash excludes captured_at so re-snapshotting unchanged content is idempotent.
        $hashInput = $payload;
        unset($hashInput['captured_at']);
        $contentHash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        // forceCreate() bypasses the model's $guarded = ['id'] so the explicit UUID is stored.
        return Evidence::forceCreate([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'case_id' => $caseId,
            'signal_id' => $signalId,
            'evidence_type' => 'content_snapshot',
            'payload' => $payload,
            'content_hash' => $contentHash,
        ]);
    }

    private function snapshotSite(string $siteId): array
    {
        // Load site with user (not 'professional' — that relation does not exist).
        // blocks eager-loaded to count block types without N+1.
        $site = Site::query()->with(['user', 'blocks'])->findOrFail($siteId);

        return [
            'site_id' => $site->id,
            'site_subdomain' => $site->subdomain ?? null,
            'user_id' => $site->user_id,
            'handle' => $site->user?->handle ?? null,
            'display_name' => $site->user?->display_name ?? null,
            'bio' => $site->user?->bio ?? null,
            'block_count' => $site->blocks?->count() ?? 0,
            'block_types' => $site->blocks?->pluck('block_type')->all() ?? [],
        ];
    }
}
