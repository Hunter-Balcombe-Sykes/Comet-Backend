<?php

namespace App\Jobs\Cloudflare;

use App\Jobs\Concerns\HasCloudflareRetryPolicy;
use App\Models\Core\User\User;
use App\Services\Cloudflare\CloudflareKvService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Syncs one professional's subdomain routing entries in Cloudflare KV.
// All accounts are individual; only {"type":"individual"} entries are written.
// Historical aliases: {"type":"alias","redirect":"https://<current>.partna.au"}
// with expirationTtl. The Worker reads `entry.redirect` as a full URL — it
// does NOT reconstruct from a handle. Pre-computing the URL here keeps
// alias entries valid against the canonical subdomain regardless of future
// Worker-side URL composition changes. Genuine deletes go through
// RetireSubdomainFromKvJob, NOT this job.
//
// `ShouldBeUnique` with a 45s window collapses observer storms to a single KV write per 45s.
class SyncSubdomainToKvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HasCloudflareRetryPolicy, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $uniqueFor = 45;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(CloudflareKvService $kv): void
    {
        $pro = User::query()->find($this->userId);

        if (! $pro || ! $pro->handle) {
            return;
        }

        $current = strtolower(trim((string) $pro->handle));

        // All accounts are individual. The Astro Worker reads this entry via
        // Service Binding and renders the public profile page.
        $kv->put($current, ['type' => 'individual'], null);

        // Pre-compute the canonical URL for alias-redirect entries. Format
        // matches site.compute_user_url() — `https://<handle>.partna.au` —
        // and the trg_recompute_partna_url DB trigger keeps
        // core.users.partna_url in sync so this fallback is rarely needed.
        $canonical = $pro->partna_url ?: "https://{$current}.partna.au";
        $this->writeAliasEntries($kv, $pro->id, $current, $canonical);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.sync_subdomain_to_kv.failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Write alias KV entries — {type:'alias', redirect:'https://<current>.partna.au'}
     * with a TTL derived from expires_at so Cloudflare auto-evicts when the
     * alias expires. Legacy NULL-expires_at aliases get no TTL (permanent
     * until the next prune sweep).
     *
     * The Worker contract reads `entry.redirect` as a full URL — sending
     * `target: <handle>` (the older shape) silently falls through the
     * Worker's alias branch and routes to fetch(request), producing an
     * infinite self-loop that surfaces to the visitor as a 522.
     */
    private function writeAliasEntries(CloudflareKvService $kv, string $proId, string $current, string $canonical): void
    {
        $aliases = DB::table('core.user_handle_aliases')
            ->where('user_id', $proId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        foreach ($aliases as $alias) {
            $handle = strtolower(trim($alias->handle));
            if ($handle === '' || $handle === $current) {
                continue;
            }

            // max(60, ...) so we never pass a sub-minimum TTL to CF KV.
            $ttl = $alias->expires_at
                ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
                : null;

            $kv->put($handle, ['type' => 'alias', 'redirect' => $canonical], $ttl);
        }
    }
}
