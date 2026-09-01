<?php

namespace App\Services\Platforms\Registry;

use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Services\Platforms\Normalizers\DiscordNormalizer;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Normalizers\KickNormalizer;
use App\Services\Platforms\Normalizers\LinkedinNormalizer;
use App\Services\Platforms\Normalizers\MediumNormalizer;
use App\Services\Platforms\Normalizers\RedditNormalizer;
use App\Services\Platforms\Normalizers\SkoolNormalizer;
use App\Services\Platforms\Normalizers\SnapchatNormalizer;
use App\Services\Platforms\Normalizers\StravaNormalizer;
use App\Services\Platforms\Normalizers\TelegramNormalizer;
use App\Services\Platforms\Normalizers\ThreadsNormalizer;
use App\Services\Platforms\Normalizers\TiktokNormalizer;
use App\Services\Platforms\Normalizers\XNormalizer;
use App\Services\Platforms\Payloads\LinkPayload;

/**
 * PD-retirement P2 (2026-08-27): the link-only connect contracts, keyed by
 * registry slug, so DerivedDescriptorFactory can produce LINK-ONLY-shaped
 * descriptors (username/url field, UrlConnect + the platform's exact 422
 * copy, LinkPayload, LinkConnectionResource) instead of the Brand default.
 * The data below is the retired hand-written registration VERBATIM — the
 * 422 strings are pinned by tests and must not drift.
 *
 * A slug with a `normalizer` gets its UrlConnect strategy attached; one
 * without (whatsapp, substack, …) derives connect-strategy-less exactly as
 * its hand-written PD::linkOnly() was before the upgrades() pass, which
 * still runs and retro-fits the Brand connect where the catalog surface is
 * connectable — byte-identical behaviour, different author.
 *
 * `category` overrides the routing-class derivation for the demoted
 * platforms whose dashboard grouping must not move (skool / strava —
 * convergence-phases §1.2; twitch carried the same override here until its
 * 2026-09-01 move to TwitchBinding, which keeps it).
 */
final class LinkOnlyBindings
{
    /**
     * @return array{label: string, normalizer: ?class-string, error: ?string, category: ?PlatformCategory, field: ?string, max: ?int}|null
     */
    public static function for(string $slug): ?array
    {
        return self::MAP[$slug] ?? null;
    }

    /** @return list<string> every slug this table retires from hand-written registration. */
    public static function slugs(): array
    {
        return array_keys(self::MAP);
    }

    public static function payloadClass(): string
    {
        return LinkPayload::class;
    }

    public static function resourceClass(): string
    {
        return LinkConnectionResource::class;
    }

    private const MAP = [
        'x' => ['label' => 'X', 'normalizer' => XNormalizer::class, 'error' => 'Enter your X handle or profile URL (x.com/yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'linkedin' => ['label' => 'LinkedIn', 'normalizer' => LinkedinNormalizer::class, 'error' => 'Enter your LinkedIn profile URL (linkedin.com/in/yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'threads' => ['label' => 'Threads', 'normalizer' => ThreadsNormalizer::class, 'error' => 'Enter your Threads handle or profile URL (threads.net/@yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'reddit' => ['label' => 'Reddit', 'normalizer' => RedditNormalizer::class, 'error' => 'Enter your Reddit username or community (u/yourname or r/yourcommunity).', 'category' => null, 'field' => 'username', 'max' => 200],
        'tiktok' => ['label' => 'TikTok', 'normalizer' => TiktokNormalizer::class, 'error' => 'Enter your TikTok username or profile URL.', 'category' => null, 'field' => 'username', 'max' => 200],
        'facebook' => ['label' => 'Facebook', 'normalizer' => FacebookNormalizer::class, 'error' => 'Enter your Facebook username or profile URL.', 'category' => null, 'field' => 'username', 'max' => 200],
        'snapchat' => ['label' => 'Snapchat', 'normalizer' => SnapchatNormalizer::class, 'error' => 'Enter your Snapchat username or profile URL (snapchat.com/add/yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'discord' => ['label' => 'Discord', 'normalizer' => DiscordNormalizer::class, 'error' => 'Enter your Discord invite link or code (discord.gg/yourcode).', 'category' => null, 'field' => 'username', 'max' => 200],
        'telegram' => ['label' => 'Telegram', 'normalizer' => TelegramNormalizer::class, 'error' => 'Enter your Telegram username or profile URL (t.me/yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'kick' => ['label' => 'Kick', 'normalizer' => KickNormalizer::class, 'error' => 'Enter your Kick username or channel URL (kick.com/yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'medium' => ['label' => 'Medium', 'normalizer' => MediumNormalizer::class, 'error' => 'Enter your Medium username or profile URL (medium.com/@yourname).', 'category' => null, 'field' => 'username', 'max' => 200],
        'skool' => ['label' => 'Skool', 'normalizer' => SkoolNormalizer::class, 'error' => 'Enter your Skool community URL (skool.com/yourcommunity).', 'category' => PlatformCategory::Education, 'field' => 'url', 'max' => 500],
        'strava' => ['label' => 'Strava', 'normalizer' => StravaNormalizer::class, 'error' => 'Enter your Strava club URL (strava.com/clubs/yourclub).', 'category' => PlatformCategory::Content, 'field' => 'url', 'max' => 300],
        // twitch left for BEHAVIOUR_BINDINGS 2026-09-01 (Item 10a): its
        // contract outgrew the link-only shape — see TwitchBinding, which
        // carries the row's frozen strings (422 copy, Streaming category,
        // url field + max:120) verbatim.
        'whatsapp' => ['label' => 'WhatsApp', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'substack' => ['label' => 'Substack', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'patreon' => ['label' => 'Patreon', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'ko-fi' => ['label' => 'Ko-fi', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'buymeacoffee' => ['label' => 'Buy Me a Coffee', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'github' => ['label' => 'GitHub', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'gitlab' => ['label' => 'GitLab', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'codepen' => ['label' => 'CodePen', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'dribbble' => ['label' => 'Dribbble', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'behance' => ['label' => 'Behance', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
        'gumroad' => ['label' => 'Gumroad', 'normalizer' => null, 'error' => null, 'category' => null, 'field' => null, 'max' => null],
    ];
}
