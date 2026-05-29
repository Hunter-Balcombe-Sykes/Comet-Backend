<?php

use App\Models\Core\User\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('setupContactInboxSchema')) {
    function setupContactInboxSchema(): void
    {
        attachTestSchemas();

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            site_id TEXT NOT NULL,
            customer_id TEXT NULL,
            notification_id TEXT NULL,
            name TEXT NULL,
            email TEXT NULL,
            phone TEXT NULL,
            subject TEXT NOT NULL,
            message TEXT NULL,
            ip_hash TEXT NULL,
            user_agent TEXT NULL,
            status TEXT NOT NULL DEFAULT "new",
            read_at TEXT NULL,
            replied_at TEXT NULL,
            archived_at TEXT NULL,
            spam_at TEXT NULL,
            redacted_at TEXT NULL,
            email_sent_at TEXT NULL,
            confirmation_sent_at TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.customers (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            email TEXT NULL,
            phone TEXT NULL,
            full_name TEXT NULL,
            source TEXT NULL,
            external_id TEXT NULL,
            marketing_opt_in_cached INTEGER NULL,
            notes TEXT NULL,
            redacted_at TEXT NULL,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            type TEXT NOT NULL,
            category TEXT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            cta_url TEXT NULL,
            primary_action_label TEXT NULL,
            secondary_action_label TEXT NULL,
            secondary_action_url TEXT NULL,
            severity TEXT NULL,
            starts_at TEXT NULL,
            ends_at TEXT NULL,
            dedupe_key TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            email_sent_at TEXT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
            id TEXT PRIMARY KEY,
            notification_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            read_at TEXT NULL,
            dismissed_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
            id TEXT PRIMARY KEY,
            site_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            block_type TEXT NOT NULL,
            block_group TEXT NOT NULL,
            settings TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
    }
}

if (! function_exists('makeInboxUser')) {
    function makeInboxUser(): User
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('core.users')->insert([
            'id' => $id,
            'handle' => 'inbox-'.substr($id, 0, 8),
            'handle_lc' => 'inbox-'.substr($id, 0, 8),
            'display_name' => 'Inbox Pro',
            'primary_email' => 'inbox-'.substr($id, 0, 8).'@example.com',
            'status' => 'active',
        ]);

        return User::query()->findOrFail($id);
    }
}

if (! function_exists('seedInboxEnquiry')) {
    function seedInboxEnquiry(string $userId, string $siteId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.enquiries')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'site_id' => $siteId,
            'name' => 'Sarah',
            'email' => 's@e.com',
            'subject' => 'Press',
            'message' => 'A ten-char message here.',
            'status' => 'new',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides));

        return $id;
    }
}

if (! function_exists('seedInboxCustomer')) {
    function seedInboxCustomer(string $userId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.customers')->insert(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'email' => 'cust@example.com',
            'full_name' => 'Customer',
            'source' => 'enquiry',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ], $overrides));

        return $id;
    }
}

if (! function_exists('seedContactBlock')) {
    function seedContactBlock(string $siteId, string $userId, array $settings = []): string
    {
        $id = (string) Str::uuid();
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'block_type' => 'contact',
            'block_group' => 'sections',
            'settings' => json_encode(array_merge([
                'notification_email' => 'pro@example.com',
                'subject_options' => ['Press', 'General'],
            ], $settings)),
            'is_active' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $id;
    }
}

if (! function_exists('requestAs')) {
    function requestAs(User $user, string $method = 'GET', string $uri = '/api/me/enquiries', array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        // Attribute is still 'professional' (request attributes weren't renamed when the column was).
        $request->attributes->set('professional', $user);

        return $request;
    }
}
