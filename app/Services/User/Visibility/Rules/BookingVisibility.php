<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Models\Core\User\Service;
use App\Services\User\Visibility\SectionVisibilityContract;
use Illuminate\Support\Facades\DB;

// Booking is publishable when there is at least one active service AND a booking
// destination (a links-group block tagged category='booking', or a legacy
// booking_url stored on the booking section block itself). Smart-booking
// (Square/Fresha integration) was dropped — a platform integration never
// satisfies booking on its own.
class BookingVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'booking';
    }

    public function contextSubqueries(string $userId, string $siteId): array
    {
        return [
            // Gating requirement: at least one active MANUAL service (Fresha
            // projections don't change this gate — pre-projection behaviour kept).
            'has_active_service' => Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNull('source')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery(),

            // Current "has a booking destination" path: a links-group block with
            // category='booking'. Phase 2: reads the promoted column (not settings JSONB).
            'has_booking_link_block' => Block::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('block_group', Block::GROUP_LINKS)
                ->where('category', 'booking')
                ->whereNull('deleted_at')
                ->getQuery(),
        ];
    }

    public function resolve(Block $block, array $context, ?array $pendingSettings = null): array
    {
        if (! ($context['has_active_service'] ?? false)) {
            return [false, 'Booking section requires at least 1 active service.'];
        }

        // Smart-booking (Square/Fresha) dropped → integration always false; the
        // only remaining pass conditions are a booking link block or a legacy url.
        if ($context['has_booking_link_block'] ?? false) {
            return [true, null];
        }

        // Legacy fallback: booking_url stored on the booking section block itself.
        // Read from the loaded block (no DB hit). Single-check path: the
        // orchestrator loads the live section row (or a transient skeleton with
        // empty settings); batch path: the already-loaded block.
        $settings = is_array($block->settings) ? $block->settings : [];
        $url = data_get($settings, 'booking_url');
        if (is_string($url) && trim($url) !== '') {
            return [true, null];
        }

        return [false, 'Booking section requires a booking link or booking integration.'];
    }
}
