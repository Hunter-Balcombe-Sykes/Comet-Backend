<?php

namespace App\Services\Content;

use App\Services\Platforms\EventPageReader;
use App\Services\Platforms\MediaPageReader;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Site\Pools\PoolRegistry;

// What a pasted link LOOKS LIKE, before anything is created — pure grammar,
// no fetch, so the add sheets can show "this looks like a Spotify track —
// add it on Listen" in STEP 1, ahead of the Continue button (owner,
// 2026-08-20: the cross-pool guidance arriving as a 422 toast after submit
// was the complaint). The pool endpoints keep their own 422s as the
// backstop; this is the same grammar surfaced earlier.
class PastedLinkClassifier
{
    public function __construct(
        private readonly MediaPageReader $media,
        private readonly EventPageReader $events,
        private readonly WebsiteLinkHarvester $harvester,
    ) {}

    /**
     * Path segments that are platform chrome, never a profile handle, on the
     * social hosts. profileShaped() refuses them as single-segment handles so
     * an instagram.com/reel/… or /explore/… never reads as "a profile".
     */
    private const PROFILE_CHROME = [
        'reel', 'reels', 'p', 'tv', 'stories', 'explore', 'video', 'videos',
        'watch', 'shorts', 'live', 'share', 'sharer', 'post', 'posts',
        'status', 'hashtag', 'tag', 'tags', 'groups', 'events', 'pages',
        'help', 'about', 'legal', 'privacy', 'terms', 'business', 'developer',
        'developers', 'blog', 'jobs', 'careers', 'search', 'directory',
        'discover', 'settings', 'login', 'signup', 'home', 'intent', 'dialog',
    ];

    /**
     * @return array{
     *   belongsTo: array{pool: string, kind: string, pageLabel: string}|null,
     *   account: string|null,
     *   store: string|null,
     * }
     */
    public function classify(string $url): array
    {
        $item = $this->media->classifyItem($url);
        if ($item !== null) {
            return $this->answer(belongsTo: $this->belongsTo($item['kind']));
        }

        $account = $this->media->accountPlatformLabel($url)
            ?? $this->events->organiserPlatformLabel($url);
        if ($account !== null) {
            return $this->answer(account: $account);
        }

        // Events + social + shop grammar lives in the harvester (pure — regex
        // arms plus the no-fetch catalog projection). Event/organiser drive
        // the events pool's answers; a social PROFILE is a connectable
        // account (T3); a known store-platform host is a STORE (T4 — the
        // sheets say "connect it on your Sell page" and the pool endpoints'
        // 422 reads the same answer). Every other category is not this
        // classifier's business.
        $classified = $this->harvester->classify($url);
        if (($classified['category'] ?? null) === 'event') {
            return $this->answer(belongsTo: $this->belongsTo('event'));
        }
        if (($classified['category'] ?? null) === 'event-organiser') {
            return $this->answer(account: (string) $classified['label']);
        }
        // The harvester's looksLikeProfile() is a bio-scan heuristic that
        // calls ANY non-root path a profile — good enough when a false
        // positive meant one extra social-icon row, wrong as the copy on a
        // hard refusal (an instagram.com/reel/… would read "profile"). Only
        // a PROFILE-SHAPED path earns the account answer here; the rest fall
        // through to null/null — the honest "we don't recognise this" band.
        if (($classified['category'] ?? null) === 'social' && $this->profileShaped($url)) {
            return $this->answer(account: (string) $classified['label']);
        }
        if (($classified['category'] ?? null) === 'shop') {
            return $this->answer(store: (string) $classified['label']);
        }

        return $this->answer();
    }

    /**
     * Whether the grammar CLAIMS this url for the given pool — the T3 rule
     * (owner, 2026-08-20): every pool except custom_links (a card is its
     * product) and shop (STORE-FIRST reads any product page by design)
     * accepts a URL only when it reads as one of the pool's own kinds. This
     * is the ONE server-side known-platform test — the sheets' step-1 band
     * (via /content/links/classify) and the pool endpoints' 422 both read
     * this class, so they can never disagree.
     */
    public function claims(string $url, string $pool): bool
    {
        $belongsTo = $this->classify($url)['belongsTo'];

        return $belongsTo !== null && $belongsTo['pool'] === $pool;
    }

    /**
     * @param  array{pool: string, kind: string, pageLabel: string}|null  $belongsTo
     * @return array{belongsTo: array{pool: string, kind: string, pageLabel: string}|null, account: string|null, store: string|null}
     */
    private function answer(?array $belongsTo = null, ?string $account = null, ?string $store = null): array
    {
        return ['belongsTo' => $belongsTo, 'account' => $account, 'store' => $store];
    }

    /**
     * A path that reads as a PROFILE: one handle-shaped segment that isn't
     * platform chrome, or one of the few two-segment profile prefixes real
     * platforms use (/in/x, /company/x, /user/x, /c/x, /channel/x).
     */
    private function profileShaped(string $url): bool
    {
        $path = trim((string) parse_url(trim($url), PHP_URL_PATH), '/');
        if ($path === '') {
            return false;
        }

        $segments = explode('/', $path);
        if (count($segments) === 2 && in_array(strtolower($segments[0]), ['in', 'company', 'user', 'u', 'c', 'channel'], true)) {
            return preg_match('~^@?[\w.-]{2,}$~', $segments[1]) === 1;
        }

        return count($segments) === 1
            && preg_match('~^@?[\w.-]{2,}$~', $segments[0]) === 1
            && ! in_array(strtolower(ltrim($segments[0], '@')), self::PROFILE_CHROME, true);
    }

    /** @return array{pool: string, kind: string, pageLabel: string}|null */
    private function belongsTo(string $kind): ?array
    {
        $pool = PoolRegistry::poolForKind($kind);
        if ($pool === null) {
            return null;
        }

        return [
            'pool' => $pool,
            'kind' => $kind,
            'pageLabel' => PoolRegistry::PAGE_LABELS[$pool] ?? $pool,
        ];
    }
}
