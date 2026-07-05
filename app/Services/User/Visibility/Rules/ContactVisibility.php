<?php

namespace App\Services\User\Visibility\Rules;

use App\Models\Core\Site\Block;
use App\Services\User\Visibility\SectionVisibilityContract;

// Contact is publishable when it has a non-empty, valid notification_email in its
// settings. The requirement lives in the block's own payload — the single-check
// path passes the incoming settings as $pendingSettings to cover first-publish
// (config + live arrive together); the batch path passes null.
class ContactVisibility implements SectionVisibilityContract
{
    public function blockType(): string
    {
        return 'contact';
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

        $email = data_get($settings, 'notification_email');
        $email = is_string($email) ? trim($email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [false, 'Contact section requires a notification email before it can go live.'];
        }

        return [true, null];
    }
}
