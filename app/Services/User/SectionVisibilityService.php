<?php

namespace App\Services\User;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Models\Core\User\UserCredential;
use App\Models\Core\User\UserExperience;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Validates section visibility requirements. Gallery needs 1+ images; booking needs 1+ service AND a booking integration or link; services needs 1+ service with a title + price > 0.
class SectionVisibilityService
{
    /**
     * Check if a section type meets its visibility requirements.
     *
     * @param  array<string, mixed>|null  $pendingSettings  Incoming-but-not-yet-persisted settings, merged over stored
     *                                                      for block types whose requirement lives in their own payload
     *                                                      (countdown). Other block types ignore this.
     * @return array{0: bool, 1: ?string} [canBeVisible, reason]
     */
    public function checkVisibilityRequirements(
        string $userId,
        string $siteId,
        string $blockType,
        ?array $pendingSettings = null,
    ): array {
        return match ($blockType) {
            'gallery' => $this->checkGalleryRequirements($siteId),
            'booking' => $this->checkBookingRequirements($userId),
            'services' => $this->checkServicesRequirements($userId),
            'documents' => $this->checkDocumentsRequirements($siteId),
            'countdown' => $this->checkCountdownRequirements($userId, $siteId, $pendingSettings),
            'contact' => $this->checkContactRequirements($userId, $siteId, $pendingSettings),
            'public_contact' => $this->checkPublicContactRequirements($userId),
            'workplace' => $this->checkWorkplaceRequirements($siteId),
            'credentials' => $this->checkCredentialsRequirements($userId),
            'experience' => $this->checkExperienceRequirements($userId),
            default => [true, null],
        };
    }

    /**
     * Batch-evaluate visibility for a set of already-loaded section blocks.
     *
     * Loads each visibility data-source at most once (and only when at least one
     * section in the input requires it), then resolves every block against that
     * shared context. Replaces the N×checkVisibilityRequirements() pattern that
     * the index endpoint used to issue 1–4 exists() queries per section.
     *
     * @param  iterable<Block>  $sectionBlocks  Already-loaded blocks; their
     *                                          stored settings are used for
     *                                          countdown/contact (no DB call).
     * @return array<string, array{0: bool, 1: ?string}> Map of block_type → [canBeVisible, reason]
     */
    public function batchCheck(string $userId, string $siteId, iterable $sectionBlocks): array
    {
        $blocks = $sectionBlocks instanceof Collection
            ? $sectionBlocks
            : Collection::make($sectionBlocks);

        $types = $blocks->pluck('block_type')
            ->filter(fn ($t) => is_string($t))
            ->unique()
            ->values()
            ->all();

        $context = $this->loadVisibilityContext($userId, $siteId, $types);

        $byType = [];
        foreach ($blocks as $block) {
            $type = (string) ($block->block_type ?? '');
            if ($type === '' || array_key_exists($type, $byType)) {
                continue;
            }
            $byType[$type] = $this->resolveFromContext($block, $context);
        }

        return $byType;
    }

    /**
     * Build the visibility data context for the present section types in a
     * single round-trip. Each requirement becomes an `EXISTS(...)` subquery
     * inside one SELECT, so we pay one network round-trip instead of N. The
     * Eloquent-built subqueries keep the same SQL the per-helper methods
     * generate today, so dialect-specific bits (e.g. JSON arrow operators
     * for the link-block check) stay portable across pgsql and sqlite tests.
     *
     * Sites without a given section type skip the corresponding subquery.
     * Smart-booking-off mirrors the legacy `professionalHasBookingIntegration`
     * semantics: a definitive `false`, not an unknown `null`.
     *
     * @param  array<int, string>  $presentTypes
     * @return array<string, bool|null>
     */
    private function loadVisibilityContext(string $userId, string $siteId, array $presentTypes): array
    {
        $defaults = [
            'has_gallery_image' => null,
            'has_document' => null,
            'has_priced_service' => null,
            'has_active_service' => null,
            'has_booking_integration' => null,
            'has_booking_link_block' => null,
            'has_credential' => null,
            'has_experience' => null,
            'has_public_contact' => null,
            'has_workplace' => null,
        ];

        $needsGallery = in_array('gallery', $presentTypes, true);
        $needsDocument = in_array('documents', $presentTypes, true);
        $needsPricedService = in_array('services', $presentTypes, true);
        $needsBooking = in_array('booking', $presentTypes, true);
        $needsCredentials = in_array('credentials', $presentTypes, true);
        $needsExperience = in_array('experience', $presentTypes, true);
        $needsPublicContact = in_array('public_contact', $presentTypes, true);
        $needsWorkplace = in_array('workplace', $presentTypes, true);
        $subqueries = [];

        if ($needsGallery) {
            $subqueries['has_gallery_image'] = SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery();
        }

        if ($needsDocument) {
            $subqueries['has_document'] = SiteMedia::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery();
        }

        if ($needsPricedService) {
            $subqueries['has_priced_service'] = Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->where('price_cents', '>', 0)
                ->getQuery();
        }

        if ($needsBooking) {
            $subqueries['has_active_service'] = Service::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->getQuery();

            // Smart-booking (Square/Fresha integration) was dropped — has_booking_integration
            // is always false, set in the post-loop block below. No subquery needed here.

            $subqueries['has_booking_link_block'] = Block::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->where('block_group', 'links')
                ->where('settings->category', 'booking')
                ->whereNull('deleted_at')
                ->getQuery();
        }

        // Credentials / experience subqueries read from the child tables (FOUND-5).
        // Portable Eloquent — no whereRaw/JSONB arrows — so these work on both
        // the pgsql production connection and the SQLite test driver.
        if ($needsCredentials) {
            $subqueries['has_credential'] = UserCredential::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('title')
                ->where('title', '<>', '')
                ->getQuery();
        }

        if ($needsExperience) {
            $subqueries['has_experience'] = UserExperience::query()
                ->select(DB::raw('1'))
                ->where('user_id', $userId)
                ->whereNotNull('role')
                ->where('role', '<>', '')
                ->getQuery();
        }

        // Public contact: at least one of the opt-in fields is non-empty. Direct
        // text columns so this works on any driver — no JSON arrow needed.
        if ($needsPublicContact) {
            $subqueries['has_public_contact'] = User::query()
                ->select(DB::raw('1'))
                ->where('id', $userId)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereRaw("COALESCE(public_contact_number, '') <> ''")
                        ->orWhereRaw("COALESCE(public_contact_email, '') <> ''");
                })
                ->getQuery();
        }

        // Workplace: at least a name or address in site.workplaces (FOUND-4).
        // Portable Eloquent — no JSON arrows — works on both pgsql and SQLite.
        if ($needsWorkplace) {
            $subqueries['has_workplace'] = Workplace::query()
                ->select(DB::raw('1'))
                ->where('site_id', $siteId)
                ->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNotNull('name')->where('name', '<>', '');
                    })->orWhere(function ($inner) {
                        $inner->whereNotNull('address')->where('address', '<>', '');
                    });
                })
                ->getQuery();
        }

        if (empty($subqueries)) {
            // Booking section present + smart-booking dropped → integration is always false.
            if ($needsBooking) {
                $defaults['has_booking_integration'] = false;
            }

            return $defaults;
        }

        // Combine every requirement into one SELECT row of booleans. Bindings
        // accumulate in select-clause order, which matches the placeholder
        // order in the compiled SQL.
        $query = DB::query();
        foreach ($subqueries as $alias => $sub) {
            $query->selectRaw('exists ('.$sub->toSql().') as '.$alias, $sub->getBindings());
        }

        $row = $query->first();
        if ($row !== null) {
            foreach ($defaults as $col => $_) {
                if (isset($row->$col)) {
                    $defaults[$col] = (bool) $row->$col;
                }
            }
        }

        // Smart-booking dropped → has_booking_integration always false for any site
        // that exposes the booking section. (The subquery branch above no longer fetches
        // it, so this is the sole writer.)
        if ($needsBooking) {
            $defaults['has_booking_integration'] = false;
        }

        return $defaults;
    }

    /**
     * Resolve a single block's visibility from a precomputed context.
     * Booking's legacy `settings->booking_url` path reads from the loaded block,
     * not the DB — same data source, no extra round-trip.
     *
     * @param  array<string, bool|null>  $context
     * @return array{0: bool, 1: ?string}
     */
    private function resolveFromContext(Block $block, array $context): array
    {
        $type = (string) ($block->block_type ?? '');

        return match ($type) {
            'gallery' => $context['has_gallery_image']
                ? [true, null]
                : [false, 'Gallery section requires at least 1 uploaded image.'],

            'documents' => $context['has_document']
                ? [true, null]
                : [false, 'Documents section requires an uploaded document.'],

            'services' => $context['has_priced_service']
                ? [true, null]
                : [false, 'Services section requires at least 1 service with a title and price.'],

            'booking' => $this->resolveBookingFromContext($block, $context),

            'credentials' => $context['has_credential']
                ? [true, null]
                : [false, 'Credentials section requires at least 1 credential with a title.'],

            'experience' => $context['has_experience']
                ? [true, null]
                : [false, 'Experience section requires at least 1 entry with a role.'],

            'public_contact' => $context['has_public_contact']
                ? [true, null]
                : [false, 'Public contact info requires a phone number or email before it can go live.'],

            'workplace' => $context['has_workplace']
                ? [true, null]
                : [false, 'Workplace section requires a name or address before it can go live.'],

            // Countdown + contact requirements live entirely in the block's own
            // settings — no DB lookup needed when the block is already loaded.
            'countdown' => $this->resolveCountdownFromBlock($block),
            'contact' => $this->resolveContactFromBlock($block),

            default => [true, null],
        };
    }

    /**
     * @param  array<string, bool|null>  $context
     * @return array{0: bool, 1: ?string}
     */
    private function resolveBookingFromContext(Block $block, array $context): array
    {
        if (! $context['has_active_service']) {
            return [false, 'Booking section requires at least 1 active service.'];
        }

        if ($context['has_booking_integration']) {
            return [true, null];
        }

        if ($context['has_booking_link_block']) {
            return [true, null];
        }

        // Legacy fallback: booking_url stored on the section block itself.
        // Already loaded via $block->settings — no DB hit.
        $url = data_get(is_array($block->settings) ? $block->settings : [], 'booking_url');
        if (is_string($url) && trim($url) !== '') {
            return [true, null];
        }

        return [false, 'Booking section requires a booking link or booking integration.'];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function resolveCountdownFromBlock(Block $block): array
    {
        $settings = is_array($block->settings) ? $block->settings : [];

        return $this->validateCountdownSettings($settings);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function resolveContactFromBlock(Block $block): array
    {
        $settings = is_array($block->settings) ? $block->settings : [];

        return $this->validateContactSettings($settings);
    }

    /**
     * Re-evaluate and persist is_enabled for a section block based on its requirements.
     * is_active (the professional's show/hide preference) is never touched.
     */
    public function reevaluateEnabled(string $userId, string $siteId, string $blockType): void
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();

        if (! $block) {
            return;
        }

        try {
            [$canBeEnabled] = $this->checkVisibilityRequirements($userId, $siteId, $blockType);

            if ((bool) $block->is_enabled !== $canBeEnabled) {
                $block->is_enabled = $canBeEnabled;
                $block->save();
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Section is_enabled reevaluation failed', [
                'user_id' => $userId,
                'site_id' => $siteId,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function checkGalleryRequirements(string $siteId): array
    {
        if (! $this->galleryHasImage($siteId)) {
            return [false, 'Gallery section requires at least 1 uploaded image.'];
        }

        return [true, null];
    }

    private function checkServicesRequirements(string $userId): array
    {
        // The Services & Pricing section is publishable when the professional
        // has at least one active, non-deleted service with a title and a
        // price > 0. Matches the "valid enough to show publicly" bar used by
        // the dashboard's service editor — title and price are the two
        // fields customers actually see in the rendered list.
        if (! $this->professionalHasPricedService($userId)) {
            return [false, 'Services section requires at least 1 service with a title and price.'];
        }

        return [true, null];
    }

    private function checkDocumentsRequirements(string $siteId): array
    {
        if (! $this->siteHasDocument($siteId)) {
            return [false, 'Documents section requires an uploaded document.'];
        }

        return [true, null];
    }

    private function galleryHasImage(string $siteId): bool
    {
        return SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function siteHasDocument(string $siteId): bool
    {
        return SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DOCUMENTS)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function professionalHasPricedService(string $userId): bool
    {
        return Service::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->where('price_cents', '>', 0)
            ->exists();
    }

    private function professionalHasActiveService(string $userId): bool
    {
        return Service::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function professionalHasBookingIntegration(string $userId): bool
    {
        // Smart-booking (Square/Fresha integration) has been dropped — always false.
        return false;
    }

    private function professionalHasBookingLinkBlock(string $userId): bool
    {
        return Block::query()
            ->where('user_id', $userId)
            ->where('block_group', 'links')
            ->where('settings->category', 'booking')
            ->whereNull('deleted_at')
            ->exists();
    }

    private function checkCredentialsRequirements(string $userId): array
    {
        if (! $this->professionalHasCredential($userId)) {
            return [false, 'Credentials section requires at least 1 credential with a title.'];
        }

        return [true, null];
    }

    private function checkExperienceRequirements(string $userId): array
    {
        if (! $this->professionalHasExperience($userId)) {
            return [false, 'Experience section requires at least 1 entry with a role.'];
        }

        return [true, null];
    }

    // Reads from core.user_credentials — portable Eloquent, no whereRaw (FOUND-5).
    private function professionalHasCredential(string $userId): bool
    {
        return UserCredential::query()
            ->where('user_id', $userId)
            ->whereNotNull('title')
            ->where('title', '<>', '')
            ->exists();
    }

    // Reads from core.user_experience — portable Eloquent, no whereRaw (FOUND-5).
    private function professionalHasExperience(string $userId): bool
    {
        return UserExperience::query()
            ->where('user_id', $userId)
            ->whereNotNull('role')
            ->where('role', '<>', '')
            ->exists();
    }

    /**
     * A countdown is publishable when it has both a drop_time and an expiry_time,
     * with expiry strictly after drop, AND the expiry has not already elapsed.
     * Unlike gallery/services/documents/booking (requirements stored externally),
     * the countdown's requirement lives in its own settings — so the controller
     * passes the incoming payload through as $pendingSettings to cover the
     * first-time-publish path where the timeline and publication_state=live
     * arrive together.
     *
     * @param  array<string, mixed>|null  $pendingSettings
     * @return array{0: bool, 1: ?string}
     */
    private function checkCountdownRequirements(string $userId, string $siteId, ?array $pendingSettings = null): array
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'countdown')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        return $this->validateCountdownSettings($settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: bool, 1: ?string}
     */
    private function validateCountdownSettings(array $settings): array
    {
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

    /**
     * A contact block is publishable when it has a non-empty, valid
     * notification_email in its settings. Like countdown, the requirement
     * lives in the block's own payload — so the controller passes the
     * incoming settings as $pendingSettings to cover the first-publish
     * path (config + publication_state=live arrive together).
     *
     * @param  array<string, mixed>|null  $pendingSettings
     * @return array{0: bool, 1: ?string}
     */
    private function checkContactRequirements(string $userId, string $siteId, ?array $pendingSettings = null): array
    {
        $block = Block::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->first();

        $stored = $block && is_array($block->settings) ? $block->settings : [];
        $settings = $pendingSettings !== null
            ? array_replace_recursive($stored, $pendingSettings)
            : $stored;

        return $this->validateContactSettings($settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: bool, 1: ?string}
     */
    private function validateContactSettings(array $settings): array
    {
        $email = data_get($settings, 'notification_email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [false, 'Contact section requires a notification email before it can go live.'];
        }

        return [true, null];
    }

    /**
     * Public contact info goes live as soon as the professional has set at
     * least one of the two opt-in fields (public_contact_number /
     * public_contact_email). Empty / null on both → draft.
     */
    private function checkPublicContactRequirements(string $userId): array
    {
        $pro = User::query()
            ->select('public_contact_number', 'public_contact_email')
            ->where('id', $userId)
            ->whereNull('deleted_at')
            ->first();

        $hasNumber = $pro && is_string($pro->public_contact_number) && trim($pro->public_contact_number) !== '';
        $hasEmail = $pro && is_string($pro->public_contact_email) && trim($pro->public_contact_email) !== '';

        if (! $hasNumber && ! $hasEmail) {
            return [false, 'Public contact info requires a phone number or email before it can go live.'];
        }

        return [true, null];
    }

    /**
     * Workplace section goes live once the professional has at least a name
     * OR an address in site.workplaces. Either field is enough — name without
     * address is a generic-business listing; address without name is manual-only.
     * Both empty (or no row) → draft. Reads from child table (FOUND-4).
     */
    private function checkWorkplaceRequirements(string $siteId): array
    {
        $exists = Workplace::query()
            ->where('site_id', $siteId)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('name')->where('name', '<>', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('address')->where('address', '<>', '');
                });
            })
            ->exists();

        if (! $exists) {
            return [false, 'Workplace section requires a name or address before it can go live.'];
        }

        return [true, null];
    }

    private function checkBookingRequirements(string $userId): array
    {
        if (! $this->professionalHasActiveService($userId)) {
            return [false, 'Booking section requires at least 1 active service.'];
        }

        if ($this->professionalHasBookingIntegration($userId)) {
            return [true, null];
        }

        // Current path: a link block (block_group='links', settings->category='booking').
        // Replaced the old settings->booking_url field on the section block itself.
        if ($this->professionalHasBookingLinkBlock($userId)) {
            return [true, null];
        }

        // Legacy path: manual booking_url set directly on the booking section block.
        // Kept for backwards compatibility with accounts created before the link
        // block flow was introduced. Uses Laravel's portable JSON arrow syntax so
        // the query works on both Postgres (`->>`) and SQLite (`json_extract`).
        $legacyQuery = Block::query()
            ->where('user_id', $userId)
            ->where('block_group', 'sections')
            ->where('block_type', 'booking')
            ->whereNotNull('settings->booking_url')
            ->where('settings->booking_url', '!=', '');

        if (DB::connection()->getDriverName() === 'pgsql') {
            $legacyQuery->whereRaw("BTRIM(settings->>'booking_url') <> ''");
        }

        if ($legacyQuery->exists()) {
            return [true, null];
        }

        return [false, 'Booking section requires a booking link or booking integration.'];
    }
}
