<?php

// §28.5 listener tests — lightweight coverage confirming listeners fire and
// behave correctly when the AccountTypeTransitionEvent is dispatched.

use App\Enums\AccountType;
use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Listeners\Accounts\InvalidateProfessionalCacheOnTransition;
use App\Listeners\Accounts\LogAccountTypeTransition;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Log;

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
