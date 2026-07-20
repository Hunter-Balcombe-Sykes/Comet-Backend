<?php

// account_type foundation tests: the user-selectable enum (partna + business),
// the enum cast on the User model, and the account_type field on the dashboard
// resource. (Internal staff are NOT an account type — they live solely in
// core.partna_staff; see AccountType docblock.)

use App\Enums\AccountType;
use App\Http\Resources\UserDashboardResource;
use App\Models\Core\User\User;
use Illuminate\Http\Request;

describe('AccountType enum', function () {
    it('exposes exactly partna + business', function () {
        $values = array_map(fn (AccountType $c) => $c->value, AccountType::cases());
        sort($values);

        expect($values)->toBe(['business', 'partna']);
    });
});

describe('User model cast', function () {
    it('round-trips account_type through the AccountType enum', function () {
        $pro = new User(['account_type' => 'business']);

        expect($pro->account_type)->toBeInstanceOf(AccountType::class);
        expect($pro->account_type)->toBe(AccountType::Business);
    });

    it('isBusiness distinguishes the two account types', function () {
        $partna = new User(['account_type' => 'partna']);
        $business = new User(['account_type' => 'business']);

        expect($partna->isBusiness())->toBeFalse();
        expect($business->isBusiness())->toBeTrue();
    });
});

describe('UserDashboardResource — account_type', function () {
    it('includes account_type as a string value', function () {
        $pro = new User(['account_type' => 'business']);

        $payload = (new UserDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBe('business');
    });

    it('emits null for account_type when the column is unset', function () {
        $pro = new User(['account_type' => null]);

        $payload = (new UserDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBeNull();
    });
});
