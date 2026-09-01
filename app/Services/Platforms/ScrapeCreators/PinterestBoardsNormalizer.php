<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10a (2026-09-01): /v1/pinterest/user/boards → the board list the
// pinterest vendor driver walks to find pin sources. Discovery only — a board
// is a container, never a media-pool item itself; its pins are the
// candidates, fetched per board via /v1/pinterest/board.
//
// Recorded-payload facts this normalizer bakes in (food52 capture,
// 2026-09-01): rows carry type:"board" and an ABSOLUTE url (the pin
// endpoint's embedded board.url is relative — different shape, same name);
// `privacy` is present and "public" on everything the login-less endpoint
// returns, but the gate below is POSITIVE anyway — a board this class cannot
// prove public never reaches a public sitepage pipeline.
class PinterestBoardsNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/pinterest/user/boards page
     * @return list<array<string, mixed>>|null null unless the page positively
     *                                         carries at least one usable public board — a husk must read
     *                                         as "vendor miss", never as a board-less account.
     */
    public function rows(array $body): ?array
    {
        $list = $body['boards'] ?? null;
        if (! is_array($list)) {
            return null;
        }

        $rows = [];
        foreach ($list as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'board') {
                continue;
            }
            if (($item['privacy'] ?? null) !== 'public') {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
            $url = is_string($item['url'] ?? null) ? trim($item['url']) : '';
            if (preg_match('/^\d+$/', $id) !== 1 || $name === '' || ! str_starts_with($url, 'https://')) {
                continue;
            }

            $description = is_string($item['description'] ?? null) ? trim($item['description']) : '';

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'description' => $description === '' ? null : $description,
                'pin_count' => is_numeric($item['pin_count'] ?? null) ? max(0, (int) $item['pin_count']) : 0,
                'cover' => $this->cover($item),
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /** @param array<string, mixed> $item */
    private function cover(array $item): ?string
    {
        $hd = $item['image_cover_hd_url'] ?? null;
        if (is_string($hd) && $hd !== '') {
            return $hd;
        }

        $covers = is_array($item['cover_images'] ?? null) ? $item['cover_images'] : [];
        foreach (['236x', '200x150'] as $size) {
            $url = is_array($covers[$size] ?? null) ? ($covers[$size]['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $flat = $item['image_cover_url'] ?? null;

        return is_string($flat) && $flat !== '' ? $flat : null;
    }
}
