<?php

namespace App\Services\Platforms;

/**
 * A menu platform whose transport is a FIRST-PARTY HTTP fetch rather than a
 * billed Apify actor run (registry: `'transport' => 'http'`, no `actor`).
 * MenuApifyScraper::fetchStores() routes these platforms here — no
 * ApifyBudget claim, no actor URL, no per-mode scrape split — and feeds the
 * result through the same priced()/merge pipeline as every actor menu.
 *
 * First (and so far only) implementation: Square Online, whose stores expose
 * an unauthenticated structured products API (2026-08-26 probe — see
 * docs/2026-08-26-menu-item-deep-links-and-cleanup-plan.md A0.1).
 */
interface MenuHttpDriver
{
    /**
     * Fetch and normalize the store's menu in one pass. Returns the same
     * shape MenuPlatformDriver::mapItems() produces ({store, categories},
     * items carrying externalId/name/description/price/image/itemUrl/soldOut),
     * or null when the store can't be read — the caller treats null exactly
     * like a failed actor scrape (negative-cache + ghost platform).
     *
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}|null
     */
    public function fetchMenu(string $storeUrl): ?array;
}
