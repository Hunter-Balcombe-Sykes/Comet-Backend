<?php

// §28.4 — AccountTypeTransitionService unit tests.
//
// Validates the transition rules, transaction discipline (no-op on same state,
// correct DB writes), job dispatches, and event dispatches.
// Uses SQLite-backed core.professionals via setupProfessionalsTable().

use App\Enums\AccountType;
use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Exceptions\InvalidAccountTypeTransition;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountTypeTransitionService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        slot INTEGER NOT NULL DEFAULT 0,
        custom_photos_enabled INTEGER NULL,
        site_url TEXT NULL,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT NULL
    )');

    $this->service = new AccountTypeTransitionService(new BrandPartnerLinkService);
});

function makeTransitionTestPro(string $accountType = 'individual', string $professionalType = 'professional'): Professional
{
    $id = (string) Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'h-'.substr($id, 0, 8),
        'handle_lc' => 'h-'.substr($id, 0, 8),
        'display_name' => 'Test',
        'professional_type' => $professionalType,
        'account_type' => $accountType,
        'has_historical_partner_links' => 0,
        'status' => 'active',
    ]);

    return Professional::query()->findOrFail($id);
}

function makeTransitionTestBrandPro(): Professional
{
    return makeTransitionTestPro('brand', 'brand');
}

// ---------------------------------------------------------------------------
// Allowed transitions
// ---------------------------------------------------------------------------

describe('individual → partner', function () {
    it('flips account_type to partner', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('individual');
        $brand = makeTransitionTestBrandPro();

        $this->service->transition($pro, AccountType::Partner, ['brand_id' => (string) $brand->id]);

        $fresh = Professional::query()->findOrFail($pro->id);
        expect($fresh->account_type)->toBe(AccountType::Partner);
    });

    it('dispatches SyncSubdomainToKvJob', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('individual');
        $brand = makeTransitionTestBrandPro();

        $this->service->transition($pro, AccountType::Partner, ['brand_id' => (string) $brand->id]);

        Bus::assertDispatched(SyncSubdomainToKvJob::class, fn ($job) => $job->professionalId === (string) $pro->id);
    });

    it('dispatches AccountTypeTransitionEvent with correct from/to', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('individual');
        $brand = makeTransitionTestBrandPro();

        $this->service->transition($pro, AccountType::Partner, ['brand_id' => (string) $brand->id]);

        Event::assertDispatched(AccountTypeTransitionEvent::class, function ($event) use ($pro) {
            return $event->professional->id === $pro->id
                && $event->from === AccountType::Individual
                && $event->to === AccountType::Partner;
        });
    });
});

describe('partner → individual', function () {
    it('flips account_type to individual', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('partner');

        $this->service->transition($pro, AccountType::Individual);

        $fresh = Professional::query()->findOrFail($pro->id);
        expect($fresh->account_type)->toBe(AccountType::Individual);
    });

    it('dispatches SyncSubdomainToKvJob and event', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('partner');

        $this->service->transition($pro, AccountType::Individual);

        Bus::assertDispatched(SyncSubdomainToKvJob::class);
        Event::assertDispatched(AccountTypeTransitionEvent::class, fn ($e) => $e->from === AccountType::Partner && $e->to === AccountType::Individual);
    });
});

// ---------------------------------------------------------------------------
// Forbidden transitions — brand is terminal
// ---------------------------------------------------------------------------

describe('forbidden: brand → anything', function () {
    it('throws InvalidAccountTypeTransition when transitioning FROM brand to partner', function () {
        $pro = makeTransitionTestBrandPro();
        expect(fn () => $this->service->transition($pro, AccountType::Partner))
            ->toThrow(InvalidAccountTypeTransition::class);
    });

    it('throws InvalidAccountTypeTransition when transitioning FROM brand to individual', function () {
        $pro = makeTransitionTestBrandPro();
        expect(fn () => $this->service->transition($pro, AccountType::Individual))
            ->toThrow(InvalidAccountTypeTransition::class);
    });
});

describe('forbidden: anything → brand', function () {
    it('throws InvalidAccountTypeTransition when transitioning TO brand from individual', function () {
        $pro = makeTransitionTestPro('individual');
        expect(fn () => $this->service->transition($pro, AccountType::Brand))
            ->toThrow(InvalidAccountTypeTransition::class);
    });

    it('throws InvalidAccountTypeTransition when transitioning TO brand from partner', function () {
        $pro = makeTransitionTestPro('partner');
        expect(fn () => $this->service->transition($pro, AccountType::Brand))
            ->toThrow(InvalidAccountTypeTransition::class);
    });
});

// ---------------------------------------------------------------------------
// Same-state no-op
// ---------------------------------------------------------------------------

describe('same-state no-op', function () {
    it('returns without DB mutation or job dispatch when already individual', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('individual');

        $this->service->transition($pro, AccountType::Individual);

        Bus::assertNotDispatched(SyncSubdomainToKvJob::class);
        Event::assertNotDispatched(AccountTypeTransitionEvent::class);

        // account_type is unchanged
        expect(Professional::query()->findOrFail($pro->id)->account_type)->toBe(AccountType::Individual);
    });

    it('returns without dispatch when already partner', function () {
        Bus::fake();
        Event::fake();

        $pro = makeTransitionTestPro('partner');

        $this->service->transition($pro, AccountType::Partner);

        Bus::assertNotDispatched(SyncSubdomainToKvJob::class);
        Event::assertNotDispatched(AccountTypeTransitionEvent::class);
    });
});
