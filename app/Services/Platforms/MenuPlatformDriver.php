<?php

namespace App\Services\Platforms;

// One implementation per menu-scraping platform (FOUND-23 registry). Adding a
// platform = one config('partna.menu.platforms') entry + one class implementing
// this interface — MenuApifyScraper, MenuMerger, MenuSource and MenuFetchJob all
// stay platform-agnostic.
interface MenuPlatformDriver
{
    /**
     * Apify actor input for one store scrape (startUrls + any platform-specific
     * fields, e.g. DoorDash's consumer `address` locale).
     *
     * @return array<string,mixed>
     */
    public function buildInput(string $storeUrl, ?string $address): array;

    /**
     * Map the actor's raw dataset rows (`$items`, the decoded JSON response body)
     * to the normalized menu shape MenuMerger consumes.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    public function mapItems(array $items): array;
}
