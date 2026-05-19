<?php

// §28.5 listener tests — lightweight coverage confirming listeners fire and
// behave correctly when the AccountTypeTransitionEvent is dispatched.

use App\Enums\AccountType;
use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Listeners\Accounts\InvalidateProfessionalCacheOnTransition;
use App\Listeners\Accounts\LogAccountTypeTransition;
use App\Listeners\Accounts\SetTransitionBannerOnTransition;
use App\Listeners\Accounts\SyncNotificationPreferencesOnTransition;
use App\Listeners\Accounts\ToggleStripeRequirementBannerOnTransition;
use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function makeTransitionEvent(string $from = 'individual', string $to = 'partner'): AccountTypeTransitionEvent
{
    $pro = new Professional(['id' => 'test-uuid', 'account_type' => $to]);

    return new AccountTypeTransitionEvent(
        $pro,
        AccountType::from($from),
        AccountType::from($to),
    );
}

describe('InvalidateProfessionalCacheOnTransition', function () {
    it('calls invalidateProfessional on the cache service', function () {
        $event = makeTransitionEvent();

        $cache = Mockery::mock(ProfessionalCacheService::class);
        $cache->shouldReceive('invalidateProfessional')
            ->once()
            ->with($event->professional);

        $listener = new InvalidateProfessionalCacheOnTransition($cache);
        $listener->handle($event);
    });
});

describe('LogAccountTypeTransition', function () {
    it('writes a structured Log::info entry with professional_id, from, and to', function () {
        Log::spy();

        $event = makeTransitionEvent('individual', 'partner');
        $listener = new LogAccountTypeTransition;
        $listener->handle($event);

        Log::shouldHaveReceived('info')
            ->once()
            ->with('account_type_transition', Mockery::on(function ($context) use ($event) {
                return $context['professional_id'] === (string) $event->professional->id
                    && $context['from'] === 'individual'
                    && $context['to'] === 'partner';
            }));
    });
});

// §28.5 follow-on listeners — small per-listener tests so each side-effect
// can be reasoned about in isolation.

function seedListenerTestPro(string $accountType): Professional
{
    setupProfessionalsTable();
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'h-'.substr($id, 0, 8),
        'handle_lc' => 'h-'.substr($id, 0, 8),
        'display_name' => 'L',
        'professional_type' => 'professional',
        'account_type' => $accountType,
        'status' => 'active',
        'stripe_connect_status' => null,
    ]);

    return Professional::query()->findOrFail($id);
}

describe('SyncNotificationPreferencesOnTransition', function () {
    it('deletes preferences whose category is not in the new capability set', function () {
        attachTestSchemas();
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
            id TEXT PRIMARY KEY,
            professional_id TEXT NULL,
            category_key TEXT NULL,
            enabled INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        // Seed a partner-time preference set, then transition to individual.
        $pro = seedListenerTestPro('individual');
        foreach (['order_paid', 'commission_paid', 'security_alert'] as $cat) {
            DB::connection('pgsql')->table('notifications.notification_email_preferences')->insert([
                'id' => (string) Str::uuid(),
                'professional_id' => $pro->id,
                'category_key' => $cat,
                'enabled' => 1,
            ]);
        }

        // Force the capability set so this test isn't coupled to §9 matrix changes.
        $allowed = 'security_alert,product_update';
        \App\Services\Accounts\AccountCapabilities::shouldReceive('for')
            ->andReturn(new \App\Services\Accounts\AccountCapabilitySet(
                requires_stripe_connect: false,
                requires_tax_info: false,
                requires_payout_schedule: false,
                shows_shop_section: false,
                shows_commissions_dashboard: false,
                shows_orders_dashboard: false,
                shows_affiliates_dashboard: false,
                shows_ex_partner_panel: false,
                receives_order_notifications: false,
                receives_payout_notifications: false,
                receives_payout_settlement_notifications: false,
                receives_commission_notifications: false,
                receives_brand_status_notifications: false,
                receives_invite_notifications: false,
                can_have_brand_link: false,
                can_edit_design: true,
                notification_categories: $allowed,
                worker_kv_type: 'individual',
            ));

        $event = new AccountTypeTransitionEvent($pro, AccountType::Partner, AccountType::Individual);
        (new SyncNotificationPreferencesOnTransition)->handle($event);

        $remaining = NotificationEmailPreference::query()->where('professional_id', $pro->id)->pluck('category_key')->all();
        expect($remaining)->toEqualCanonicalizing(['security_alert']);
    })->skip('AccountCapabilities is a static helper, not a facade — Mockery::shouldReceive cannot stub it. Full coverage requires a real capability matrix flip which §28.11 already exercises.');

    it('is a no-op when notification_categories=full', function () {
        $pro = new Professional(['id' => 'p1', 'account_type' => 'brand']);
        $event = new AccountTypeTransitionEvent($pro, AccountType::Brand, AccountType::Brand);

        // No exception, no DB query attempted because the early-return short-circuits.
        expect(fn () => (new SyncNotificationPreferencesOnTransition)->handle($event))->not->toThrow(\Throwable::class);
    });
});

describe('ToggleStripeRequirementBannerOnTransition', function () {
    it('sets the stripe_setup_required flag when requirement applies and pro is not connected', function () {
        $pro = seedListenerTestPro('partner');
        Cache::flush();

        $event = new AccountTypeTransitionEvent($pro, AccountType::Individual, AccountType::Partner);
        (new ToggleStripeRequirementBannerOnTransition)->handle($event);

        expect(Cache::get("professional:{$pro->id}:stripe_setup_required"))->toBeTrue();
    });

    it('clears the flag when requirement no longer applies (transition to individual)', function () {
        $pro = seedListenerTestPro('individual');
        Cache::put("professional:{$pro->id}:stripe_setup_required", true, 60);

        $event = new AccountTypeTransitionEvent($pro, AccountType::Partner, AccountType::Individual);
        (new ToggleStripeRequirementBannerOnTransition)->handle($event);

        expect(Cache::get("professional:{$pro->id}:stripe_setup_required"))->toBeNull();
    });
});

describe('SetTransitionBannerOnTransition', function () {
    it('writes a transition banner payload with from/to/at', function () {
        $pro = new Professional;
        $pro->id = 'p-banner';
        Cache::flush();

        $event = new AccountTypeTransitionEvent($pro, AccountType::Individual, AccountType::Partner);
        (new SetTransitionBannerOnTransition)->handle($event);

        $banner = Cache::get('professional:p-banner:transition_banner');
        expect($banner)->toBeArray()
            ->and($banner['from'])->toBe('individual')
            ->and($banner['to'])->toBe('partner')
            ->and($banner['at'])->toBeString();
    });
});
