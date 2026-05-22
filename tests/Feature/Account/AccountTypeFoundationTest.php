<?php

// account_type foundation tests: enum cast + the account_type field on the
// dashboard resource. The original migration-sequence schema-doc tests were
// removed in the standalone strip — the incremental account_type migrations
// were folded into the consolidated baseline.

use App\Enums\AccountType;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\User;
use Illuminate\Http\Request;

describe('AccountType enum', function () {
    it('has exactly one case — individual (brand and partner dropped in standalone strip)', function () {
        $values = array_map(fn (AccountType $c) => $c->value, AccountType::cases());
        sort($values);

        expect($values)->toBe(['individual']);
    });
});

describe('Professional model cast', function () {
    it('round-trips account_type through the AccountType enum', function () {
        $pro = new User(['account_type' => 'individual']);

        expect($pro->account_type)->toBeInstanceOf(AccountType::class);
        expect($pro->account_type)->toBe(AccountType::Individual);
    });

    it('isIndividual returns true for individual account_type', function () {
        $individual = new User(['account_type' => 'individual']);

        expect($individual->isIndividual())->toBeTrue();
    });
});

describe('ProfessionalDashboardResource — Track B §28.8a unblock', function () {
    it('includes account_type as a string value', function () {
        $pro = new User(['account_type' => 'individual']);

        $payload = (new ProfessionalDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBe('individual');
    });

    it('emits null for account_type when the column is unset (pre-backfill rows)', function () {
        $pro = new User(['account_type' => null]);

        $payload = (new ProfessionalDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBeNull();
    });
});
