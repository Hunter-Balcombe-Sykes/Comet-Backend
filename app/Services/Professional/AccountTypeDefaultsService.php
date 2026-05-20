<?php

namespace App\Services\Professional;

use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;

// V2: Applies account-type defaults (influencer, professional, brand, affiliate) to new professionals and their sites. Handles config inheritance and affiliate-specific overlays.
class AccountTypeDefaultsService
{
    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    /**
     * Resolve the effective config for a professional type, handling inheritance.
     */
    public function resolveDefaults(string $professionalType): array
    {
        $all = config('partna.account_type_defaults', []);
        $typeConfig = $all[$professionalType] ?? $all['influencer'] ?? [];

        if (isset($typeConfig['inherits'])) {
            $parent = $all[$typeConfig['inherits']] ?? [];
            $typeConfig = array_replace_recursive($parent, $typeConfig);
            unset($typeConfig['inherits']);
        }

        return $typeConfig;
    }

    /**
     * Apply defaults to a newly created professional's site and blocks.
     */
    public function applyDefaults(Professional $professional, Site $site): void
    {
        // Prefer the canonical account_type when set; fall back to legacy
        // professional_type only for pre-§28.1-backfill rows (audit #2).
        $type = $professional->account_type?->value ?? $professional->professional_type;
        $defaults = $this->resolveDefaults($type);

        // 1. Set is_published
        if (isset($defaults['is_published'])) {
            $site->is_published = (bool) $defaults['is_published'];
        }

        // 2. Apply default site settings (e.g. brand system colours/font)
        if (isset($defaults['default_site_settings'])) {
            $existing = is_array($site->settings) ? $site->settings : [];
            $site->settings = array_replace_recursive($existing, $defaults['default_site_settings']);
        }

        $site->save();

        // 3. Create section blocks for allowed account-type sections.
        // For professional/influencer accounts we keep these enabled + draft by default.
        $allowedSections = $defaults['allowed_sections'] ?? [];
        $defaultSections = $defaults['default_sections'] ?? [];
        $sectionsToCreate = $professional->isBrand() ? $defaultSections : $allowedSections;
        foreach (array_values($sectionsToCreate) as $sortOrder => $blockType) {
            [$isEnabled] = $this->visibilityService->checkVisibilityRequirements(
                (string) $professional->id,
                (string) $site->id,
                $blockType
            );
            Block::query()->firstOrCreate(
                [
                    'professional_id' => $professional->id,
                    'site_id' => $site->id,
                    'block_group' => 'sections',
                    'block_type' => $blockType,
                ],
                [
                    'sort_order' => $sortOrder,
                    'is_enabled' => $isEnabled,
                    'is_active' => false,
                    'settings' => [],
                ]
            );
        }

        // 4. Create default contact (for non-brand types)
        if (isset($defaults['default_contact'])) {
            $this->createDefaultContact($professional, $defaults['default_contact']);
        }
    }

    /**
     * Apply affiliate-specific overlay when connecting to a brand.
     */
    public function applyAffiliateDefaults(
        Professional $professional,
        Site $site,
    ): void {
        $config = config('partna.account_type_defaults.affiliate', []);

        // 1. Auto-enable shop section
        $autoSections = $config['auto_enable_sections'] ?? [];
        foreach ($autoSections as $blockType) {
            $block = Block::query()->firstOrNew([
                'professional_id' => $professional->id,
                'site_id' => $site->id,
                'block_group' => 'sections',
                'block_type' => $blockType,
            ]);

            // is_enabled (data-requirements gate) is computed honestly so this
            // path stays consistent if auto_enable_sections grows beyond 'shop'
            // (which has no requirements). Today shop always evaluates to true,
            // so behaviour is unchanged for the current config.
            [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                (string) $professional->id,
                (string) $site->id,
                $blockType,
            );
            $block->is_enabled = $canBeEnabled;
            $block->is_active = true;

            if (! $block->exists) {
                $maxSort = Block::query()
                    ->where('site_id', $site->id)
                    ->where('block_group', 'sections')
                    ->max('sort_order');

                $block->sort_order = is_null($maxSort) ? 0 : ((int) $maxSort + 1);
                $block->settings = [];
            }

            $block->save();
        }

    }

    private function createDefaultContact(Professional $professional, array $contactData): void
    {
        $email = $contactData['email'] ?? null;
        if (! $email) {
            return;
        }

        $exists = Customer::query()
            ->where('professional_id', $professional->id)
            ->where('email', $email)
            ->exists();

        if (! $exists) {
            $customer = new Customer([
                'full_name' => $contactData['full_name'] ?? null,
                'email' => $email,
                'phone' => $contactData['phone'] ?? null,
                'source' => $contactData['source'] ?? 'system_default',
            ]);
            $customer->professional_id = $professional->id;
            $customer->save();
        }

        // Create marketing subscription if configured
        if ($contactData['subscribed'] ?? false) {
            $sub = EmailSubscription::query()->firstOrNew([
                'professional_id' => $professional->id,
                'list_key' => 'marketing',
                'email_lc' => strtolower($email),
            ]);

            if (! $sub->exists) {
                $sub->email = $email;
                $sub->full_name = $contactData['full_name'] ?? null;
                $sub->unsubscribe_token = EmailSubscription::newUnsubscribeToken();
                $sub->markSubscribed(['source' => 'account_default']);
                $sub->save();
            }
        }
    }
}
