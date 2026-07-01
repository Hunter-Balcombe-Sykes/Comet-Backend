<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;
use Carbon\CarbonImmutable;

// Countdown is publishable when it has both a drop_time and an expiry_time, with
// expiry strictly after drop AND not already past. The requirement lives in the
// block's own settings — no DB lookup. The single-check (first-publish) path
// passes the incoming payload as $pendingSettings so timeline + live arriving in
// the same request see the pending values; the batch path passes null.
class CountdownVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'countdown';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        $stored = is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        $drop = data_get($settings, 'timeline.drop_time');
        $expiry = data_get($settings, 'timeline.expiry_time');

        if (! is_string($drop) || $drop === '') {
            return [false, 'Countdown section requires a drop time before it can go live.'];
        }

        if (! is_string($expiry) || $expiry === '') {
            return [false, 'Countdown section requires an expiry time before it can go live.'];
        }

        try {
            $dropTs = CarbonImmutable::parse($drop);
            $expiryTs = CarbonImmutable::parse($expiry);
        } catch (\Throwable) {
            return [false, 'Countdown section has an invalid drop time or expiry time.'];
        }

        if ($expiryTs->lessThanOrEqualTo($dropTs)) {
            return [false, 'Countdown expiry time must be after the drop time.'];
        }

        if ($expiryTs->isPast()) {
            return [false, 'Countdown expiry time is already in the past.'];
        }

        return [true, null];
    }
}
