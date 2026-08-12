<?php

namespace App\Mail\Branding;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Design\ProfileDesignPresets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a per-site EmailBrand (pro name, logo, palette, reply-to) for
 * white-label visitor emails. The only DB/cache-touching unit in the branding
 * stack — everything downstream is pure data + rendering.
 *
 * Cached per site through CacheLockService::rememberLocked (single-flight +
 * jitter + SWR), so a broadcast to N recipients of one pro resolves once.
 * Invalidated via SiteCacheService::invalidateSite (see SiteCacheService).
 */
class ProEmailBrandResolver
{
    public function __construct(private readonly CacheLockService $cacheLock) {}

    public function partna(): EmailBrand
    {
        return EmailBrand::partna();
    }

    public function forSite(string $siteId): EmailBrand
    {
        $key = CacheKeyGenerator::emailBrand($siteId);
        $ttl = (int) config('partna.cache.ttls.email_brand', 86400);

        $payload = $this->cacheLock->rememberLocked(
            $key,
            $ttl,
            fn (): array => $this->build($siteId)->toArray(),
        );

        return EmailBrand::fromArray($payload);
    }

    public function forget(string $siteId): void
    {
        $key = CacheKeyGenerator::emailBrand($siteId);
        Cache::deleteMultiple([$key, $key.':stale']);
    }

    private function build(string $siteId): EmailBrand
    {
        $site = Site::query()->with('user')->find($siteId);
        if ($site === null) {
            return EmailBrand::partna();
        }

        $user = $site->user;

        $proName = trim((string) ($user->display_name ?? '')) ?: 'the team';
        // Eager-loaded relation preserves the null-handling of the old
        // `$site->user_id ? User::find(...) : null` lookup — a null FK simply
        // yields a null relation, no extra branching needed.
        $domain = (string) (config('partna.public_domain') ?: 'partna.au');
        $siteUrl = ($user && $user->handle)
            ? 'https://'.$user->handle.'.'.$domain
            : 'https://'.$domain;

        // Merge the profile-derived preset layer under the user's manual kit
        // (manual wins) so white-label emails reflect the same auto-styling
        // as the sitepage.
        $manualRow = (array) (DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->first() ?? []);
        unset($manualRow['site_id']);
        $manual = array_filter($manualRow, static fn ($v) => $v !== null);
        $kit = array_merge(ProfileDesignPresets::forUser($user), $manual);

        return new EmailBrand(
            isPartna: false,
            proName: $proName,
            siteUrl: $siteUrl,
            logoUrl: $this->resolveLogoUrl($siteId),
            // Pro emails render logoUrl; the light/dark wordmark pair and the
            // icon pair are the Partna first-party marks only (partna.blade.php
            // isPartna branch). All four are required constructor params with no
            // defaults — omitting them is an ArgumentCountError, not a fallback.
            iconUrl: null,
            wordmarkUrl: null,
            replyToEmail: $this->resolveReplyTo($siteId),
            palette: EmailBrandDefaults::palette($kit),
        );
    }

    /** Prefer logo_full over logo_square; only ready, active design-pool media. */
    private function resolveLogoUrl(string $siteId): ?string
    {
        $media = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderByRaw("case purpose when '".SiteMedia::PURPOSE_LOGO_FULL."' then 0 else 1 end")
            ->first();

        if ($media === null) {
            return null;
        }

        // Prefer the 'optimized' webp variant (2400px, ~500KB cap) over 'maximized'
        // (4000px, multi-MB) for email weight; fall back to whatever exists. Keyed
        // selection is deterministic — the same logo always yields the same URL.
        $urls = $media->variantUrls();
        $url = $urls['optimized'] ?? ($urls === [] ? null : reset($urls));
        $url = $url !== null ? (string) $url : null;

        return ($url !== null && $this->isSafeLogoUrl($url)) ? $url : null;
    }

    /** Defence-in-depth: https only, and (if configured) from the media host. */
    private function isSafeLogoUrl(string $url): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $disk = (string) config('partna.media_disk');
        $base = (string) config("filesystems.disks.{$disk}.url", '');
        if ($base === '') {
            return true; // no configured host to assert against
        }

        $expectedHost = parse_url($base, PHP_URL_HOST);
        $actualHost = parse_url($url, PHP_URL_HOST);

        return $expectedHost === null || $expectedHost === $actualHost;
    }

    /**
     * Contact-block inbox, else null (→ Partna default). Never the pro's
     * private Supabase account email — that must not leak into a public
     * Reply-To header just because no contact-block inbox is configured.
     */
    private function resolveReplyTo(string $siteId): ?string
    {
        $block = Block::query()
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        $email = $block ? trim((string) data_get($block->settings, 'notification_email', '')) : '';

        return $email !== '' ? $email : null;
    }
}
