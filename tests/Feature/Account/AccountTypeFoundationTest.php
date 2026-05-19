<?php

// Phase 1 PR #1: account_type foundation tests.
//
// Schema-doc tests for the 4-file migration sequence + runtime tests for the
// enum cast and the Track B unblock (account_type field on the dashboard
// resource). Migration runtime behavior (backfill correctness, trigger
// precedence, NOT VALID validation) is exercised in Supabase preview against
// a real Postgres instance per the OrdersSchemaMigrationTest precedent.

use App\Enums\AccountType;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;

describe('account_type migration sequence', function () {
    beforeEach(function () {
        $this->migrationDir = base_path('supabase/migrations');
        $this->ts1 = $this->migrationDir.'/20260520000000_add_account_type_column_and_backfill.sql';
        $this->ts2 = $this->migrationDir.'/20260520000100_add_account_type_constraints_and_trigger.sql';
        $this->ts3 = $this->migrationDir.'/20260520000200_validate_and_promote_account_type.sql';
        $this->ts4 = $this->migrationDir.'/20260520000300_add_account_type_covering_index.sql';

        foreach ([$this->ts1, $this->ts2, $this->ts3, $this->ts4] as $path) {
            expect(file_exists($path))->toBeTrue("missing migration: {$path}");
        }
    });

    it('<ts1>: adds account_type column + idempotent backfill in a single transaction', function () {
        $sql = file_get_contents($this->ts1);

        expect($sql)
            ->toContain('BEGIN;')
            ->toContain('COMMIT;')
            ->toContain('ADD COLUMN IF NOT EXISTS account_type text NULL')
            ->toContain('-- To revert:');

        // Every UPDATE branch is guarded by `account_type IS NULL` for re-run
        // safety (audit MIG-7). Count: three buckets, three guards.
        $guardCount = substr_count($sql, 'account_type IS NULL');
        expect($guardCount)->toBeGreaterThanOrEqual(3);

        // Three explicit buckets per plan §8.
        expect($sql)
            ->toContain("SET account_type = 'brand'")
            ->toContain("SET account_type = 'partner'")
            ->toContain("SET account_type = 'individual'");
    });

    it('<ts2>: adds CHECK constraints as NOT VALID and creates the dual-write trigger', function () {
        $sql = file_get_contents($this->ts2);

        expect($sql)
            ->toContain('BEGIN;')
            ->toContain('COMMIT;')
            ->toContain('professionals_account_type_check')
            ->toContain("CHECK (account_type IN ('brand', 'partner', 'individual')) NOT VALID")
            ->toContain('professionals_account_type_not_null')
            ->toContain('CHECK (account_type IS NOT NULL) NOT VALID')
            ->toContain('CREATE OR REPLACE FUNCTION core.professionals_account_type_dual_write')
            ->toContain('CREATE TRIGGER professionals_account_type_dual_write')
            ->toContain('BEFORE INSERT OR UPDATE');
    });

    it("<ts2>: trigger preserves 'influencer' / 'professional' when account_type flips to partner/individual", function () {
        // Regression guard: the prior trigger body unconditionally set
        // professional_type := 'professional' whenever account_type changed to
        // partner/individual — which destroyed the legacy 'influencer' value
        // that isInfluencer() callers still depend on. Fix preserves it.
        $sql = file_get_contents($this->ts2);

        expect($sql)
            ->toContain("NEW.professional_type IS NULL OR NEW.professional_type = 'brand'");
    });

    it('<ts3>: validates both constraints, guards against NULLs, promotes to SET NOT NULL', function () {
        $sql = file_get_contents($this->ts3);

        expect($sql)
            ->toContain('VALIDATE CONSTRAINT professionals_account_type_check')
            ->toContain('VALIDATE CONSTRAINT professionals_account_type_not_null')
            ->toContain('RAISE EXCEPTION')
            ->toContain('ALTER COLUMN account_type SET NOT NULL')
            ->toContain('DROP CONSTRAINT professionals_account_type_not_null');
    });

    it('<ts4>: builds the covering index CONCURRENTLY with no transaction wrapper', function () {
        $sql = file_get_contents($this->ts4);

        // CREATE INDEX CONCURRENTLY is incompatible with BEGIN/COMMIT — this
        // file is the documented exemption to the project's transaction rule.
        expect($sql)
            ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS professionals_account_type_idx')
            ->not->toContain('BEGIN;')
            ->not->toContain('COMMIT;');
    });
});

describe('AccountType enum', function () {
    it('has exactly three cases matching the DB CHECK constraint', function () {
        $values = array_map(fn (AccountType $c) => $c->value, AccountType::cases());
        sort($values);

        expect($values)->toBe(['brand', 'individual', 'partner']);
    });
});

describe('Professional model cast', function () {
    it('round-trips account_type through the AccountType enum', function () {
        $pro = new Professional(['account_type' => 'partner']);

        expect($pro->account_type)->toBeInstanceOf(AccountType::class);
        expect($pro->account_type)->toBe(AccountType::Partner);
    });

    it('isBrand/isPartner/isIndividual read account_type as source of truth', function () {
        $brand = new Professional(['account_type' => 'brand', 'professional_type' => 'brand']);
        $partner = new Professional(['account_type' => 'partner', 'professional_type' => 'professional']);
        $individual = new Professional(['account_type' => 'individual', 'professional_type' => 'professional']);

        expect($brand->isBrand())->toBeTrue();
        expect($brand->isPartner())->toBeFalse();
        expect($brand->isIndividual())->toBeFalse();

        expect($partner->isPartner())->toBeTrue();
        expect($partner->isBrand())->toBeFalse();
        expect($partner->isIndividual())->toBeFalse();

        expect($individual->isIndividual())->toBeTrue();
        expect($individual->isBrand())->toBeFalse();
        expect($individual->isPartner())->toBeFalse();
    });

    it('isBrand falls back to professional_type when account_type is null (dual-write safety net)', function () {
        $legacy = new Professional(['account_type' => null, 'professional_type' => 'brand']);

        expect($legacy->isBrand())->toBeTrue();
    });
});

describe('ProfessionalDashboardResource — Track B §28.8a unblock', function () {
    it('includes account_type as a string value', function () {
        $pro = new Professional(['account_type' => 'individual']);

        $payload = (new ProfessionalDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBe('individual');
    });

    it('emits null for account_type when the column is unset (pre-backfill rows)', function () {
        $pro = new Professional(['account_type' => null]);

        $payload = (new ProfessionalDashboardResource($pro))->toArray(Request::create('/'));

        expect($payload)->toHaveKey('account_type');
        expect($payload['account_type'])->toBeNull();
    });
});
