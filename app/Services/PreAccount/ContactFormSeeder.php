<?php

namespace App\Services\PreAccount;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

/**
 * T20 (owner, 2026-08-27): the contact/enquiry form is ENABLED BY DEFAULT on
 * unclaimed sites. Pre-claim, submissions route to the PUBLIC contact email
 * when one exists (seeded here with an 'auto' provenance marker); with no
 * email the block stays honestly disabled (ContactVisibility requires a
 * routable notification_email) but active — it lights up the moment an email
 * lands. At claim, an auto-seeded or empty notification email defaults to
 * the account's own email; an owner-typed one is never touched.
 */
class ContactFormSeeder
{
    public function __construct(
        private readonly SectionVisibilityService $visibility,
    ) {}

    /** Pre-account build: activate the form; route it to the public email if one exists. */
    public function seedForBuild(User $user): void
    {
        $block = $this->contactBlock($user);
        if (! $block) {
            return;
        }

        // site.blocks.settings is jsonb NOT NULL DEFAULT '{}', so Block::$settings
        // (cast 'array') is always an array — the is_array() guard PHPStan flagged
        // here was dead per that DB constraint, not defensive.
        $settings = $block->settings;
        $publicEmail = trim((string) $user->public_contact_email);

        $block->is_active = true;
        if ($publicEmail !== ''
            && filter_var($publicEmail, FILTER_VALIDATE_EMAIL) !== false
            && trim((string) data_get($settings, 'notification_email', '')) === '') {
            $settings['notification_email'] = $publicEmail;
            $settings['notification_email_source'] = 'auto';
            $block->settings = $settings;
        }
        $block->save();

        $this->visibility->reevaluateEnabled((string) $user->id, (string) $block->site_id, 'contact');
        Log::info('pre_account.contact_form_seeded', [
            'user_id' => (string) $user->id,
            'routed_to_public_email' => isset($settings['notification_email_source']),
        ]);
    }

    /** Claim: an auto-seeded or EMPTY notification email defaults to the account email. */
    public function applyClaimDefault(User $user): void
    {
        $email = trim((string) $user->primary_email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $block = $this->contactBlock($user);
        if (! $block) {
            return;
        }

        // Same NOT NULL DEFAULT '{}' guarantee as seedForBuild() above.
        $settings = $block->settings;
        $current = trim((string) data_get($settings, 'notification_email', ''));
        $isAuto = data_get($settings, 'notification_email_source') === 'auto';
        if ($current !== '' && ! $isAuto) {
            return; // the owner typed this — theirs.
        }

        $settings['notification_email'] = $email;
        unset($settings['notification_email_source']);
        $block->settings = $settings;
        $block->save();

        $this->visibility->reevaluateEnabled((string) $user->id, (string) $block->site_id, 'contact');
    }

    private function contactBlock(User $user): ?Block
    {
        $siteId = Site::query()->where('user_id', $user->id)->value('id');
        if ($siteId === null) {
            return null;
        }

        return Block::query()
            ->where('user_id', $user->id)
            ->where('site_id', $siteId)
            ->where('block_group', Block::GROUP_SECTIONS)
            ->where('block_type', 'contact')
            ->first();
    }
}
