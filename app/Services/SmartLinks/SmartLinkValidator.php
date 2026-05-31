<?php

namespace App\Services\SmartLinks;

/**
 * Per-type "is this card good enough to save?" gate (spec §7). Returns a
 * human-readable reason on failure so the dashboard can show it instead of a
 * half-broken card.
 */
class SmartLinkValidator
{
    /** @return array{valid:bool, reason:?string} */
    public function validate(string $type, ResolvedSmartLinkData $d): array
    {
        $has = fn (mixed $v) => $v !== null && $v !== '';
        $brandImage = $has($d->faviconUrl) || $has($d->brandLogoUrl);
        $price = $d->metadata['price'] ?? null;

        return match (true) {
            $type === 'commerce.brand' => ($brandImage && $has($d->brandName))
                ? $this->ok()
                : $this->fail('We couldn’t find a logo/favicon and brand name for this site.'),

            $type === 'commerce.collection' => ($has($d->title) && $has($d->imageUrl) && $brandImage && $has($d->brandName))
                ? $this->ok()
                : $this->fail('We couldn’t read this collection. Make sure it’s a public Shopify collection URL.'),

            $type === 'commerce.product' => ($has($price) && $has($d->imageUrl) && $has($d->brandName))
                ? $this->ok()
                : $this->fail('We couldn’t read this product (no price or image found). It may be a store that blocks previews.'),

            $type === 'commerce.event' => ($has($d->title) && $has($d->metadata['startsAt'] ?? null) && $has($d->imageUrl))
                ? $this->ok()
                : $this->fail('We couldn’t read this event (missing name, date, or image).'),

            str_starts_with($type, 'content.music') => ($has($d->imageUrl) && $has($d->title))
                ? $this->ok()
                : $this->fail('We couldn’t read this — check the link points to a track, album, or playlist.'),

            $type === 'content.podcast.episode' => ($has($d->title) && $has($d->imageUrl))
                ? $this->ok()
                : $this->fail('We couldn’t read this podcast episode. Make sure it’s a specific episode link.'),

            $type === 'content.video' => ($has($d->imageUrl) && $has($d->title) && $has($d->metadata['channelName'] ?? null))
                ? $this->ok()
                : $this->fail('We couldn’t read this video.'),

            default => $this->fail('Unsupported link type.'),
        };
    }

    private function ok(): array
    {
        return ['valid' => true, 'reason' => null];
    }

    private function fail(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
