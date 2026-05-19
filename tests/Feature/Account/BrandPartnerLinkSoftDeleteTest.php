<?php

// Plan §28.16 — BrandPartnerLink soft-delete + denormalized has_historical_partner_links.
//
// Schema-doc tests for the 5 migration files, runtime tests for the
// SoftDeletes trait integration, observer-maintained denorm column behavior,
// and the AccountCapabilities flip for ex-partner individuals.
//
// RLS predicate tests for the three policies in Migration C require real
// Postgres; they're documented here and exercised against the dev Supabase
// project at deploy time, not in SQLite.

use App\Enums\AccountType;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
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
});

function makeProfessional(string $accountType = 'individual'): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'h-'.substr($id, 0, 8),
        'handle_lc' => 'h-'.substr($id, 0, 8),
        'display_name' => 'Test',
        'professional_type' => $accountType === 'brand' ? 'brand' : 'professional',
        'account_type' => $accountType,
        'has_historical_partner_links' => 0,
        'status' => 'active',
    ]);

    return Professional::query()->findOrFail($id);
}

function makeLink(Professional $affiliate, Professional $brand): BrandPartnerLink
{
    return BrandPartnerLink::create([
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 0,
    ]);
}

describe('§28.16 migration sequence', function () {
    beforeEach(function () {
        $this->migrationDir = base_path('supabase/migrations');
    });

    it('Migration A1: adds soft-delete columns in BEGIN/COMMIT', function () {
        $sql = file_get_contents($this->migrationDir.'/20260520010000_add_brand_partner_links_soft_delete_columns.sql');

        expect($sql)
            ->toContain('BEGIN;')
            ->toContain('COMMIT;')
            ->toContain('brand.brand_partner_links')
            ->toContain('ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL')
            ->toContain('core.professionals')
            ->toContain('has_historical_partner_links boolean NOT NULL DEFAULT false')
            ->toContain('-- To revert:');
    });

    it('Migration A2: backfill is idempotent and runs outside a transaction (CONVENTIONS §5)', function () {
        $sql = file_get_contents($this->migrationDir.'/20260520010100_backfill_has_historical_partner_links.sql');

        expect($sql)
            ->toContain('SET has_historical_partner_links = true')
            ->toContain('has_historical_partner_links = false') // idempotency guard
            ->not->toContain('BEGIN;');
    });

    it('Migration A3: dual indexes CONCURRENTLY, no BEGIN/COMMIT', function () {
        $sql = file_get_contents($this->migrationDir.'/20260520010200_add_brand_partner_links_soft_delete_indexes.sql');

        expect($sql)
            ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS brand_partner_links_active_idx')
            ->toContain('WHERE deleted_at IS NULL')
            ->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS brand_partner_links_all_idx')
            ->not->toContain('BEGIN;');
    });

    it('Migration B: DATA-3 part B — events FKs become SET NULL', function () {
        $sql = file_get_contents($this->migrationDir.'/20260520010300_brand_partner_link_events_set_null_fks.sql');

        expect($sql)
            ->toContain('brand.brand_partner_link_events')
            ->toContain('DROP CONSTRAINT IF EXISTS brand_partner_link_events_brand_professional_id_fkey')
            ->toContain('DROP CONSTRAINT IF EXISTS brand_partner_link_events_affiliate_professional_id_fkey')
            ->toContain('DROP NOT NULL')
            ->toContain('ON DELETE SET NULL NOT VALID')
            ->toContain('VALIDATE CONSTRAINT brand_partner_link_events_brand_professional_id_fkey')
            ->toContain('VALIDATE CONSTRAINT brand_partner_link_events_affiliate_professional_id_fkey');
    });

    it('Migration C: SCHEMA-2 — the three policies gain deleted_at IS NULL predicates', function () {
        $sql = file_get_contents($this->migrationDir.'/20260520010400_update_brand_partner_link_rls_for_soft_delete.sql');

        expect($sql)
            ->toContain('DROP POLICY IF EXISTS partner_links_party_select')
            ->toContain('CREATE POLICY partner_links_party_select')
            ->toContain('DROP POLICY IF EXISTS brand_profiles_affiliate_select')
            ->toContain('CREATE POLICY brand_profiles_affiliate_select')
            ->toContain('DROP POLICY IF EXISTS store_settings_affiliate_select')
            ->toContain('CREATE POLICY store_settings_affiliate_select');

        // Defence-in-depth: deleted_at filter is present on every reissued policy.
        // Count >= 6: party_select touches the column 2x, the two _affiliate_select
        // each touch it once (on l.deleted_at), plus the unchanged p.deleted_at
        // predicates on professionals.
        expect(substr_count($sql, 'deleted_at IS NULL'))->toBeGreaterThanOrEqual(6);
    });
});

describe('BrandPartnerLink soft-delete behavior', function () {
    it('disconnect leaves the row visible to withTrashed() and hides it from default queries', function () {
        $affiliate = makeProfessional('partner');
        $brand = makeProfessional('brand');
        $link = makeLink($affiliate, $brand);

        $link->delete();

        expect(BrandPartnerLink::query()->where('id', $link->id)->exists())->toBeFalse();
        expect(BrandPartnerLink::withTrashed()->where('id', $link->id)->exists())->toBeTrue();
        expect(BrandPartnerLink::withTrashed()->find($link->id)?->trashed())->toBeTrue();
    });

    it('reconnecting after a soft-delete creates a NEW row', function () {
        $affiliate = makeProfessional('partner');
        $brand = makeProfessional('brand');

        $first = makeLink($affiliate, $brand);
        $first->delete();
        $second = makeLink($affiliate, $brand);

        expect($second->id)->not->toBe($first->id);
        expect(BrandPartnerLink::withTrashed()->where('affiliate_professional_id', $affiliate->id)->count())->toBe(2);
        expect(BrandPartnerLink::query()->where('affiliate_professional_id', $affiliate->id)->count())->toBe(1);
    });
});

describe('has_historical_partner_links observer maintenance', function () {
    it('flips to true on link creation', function () {
        $affiliate = makeProfessional('individual');
        $brand = makeProfessional('brand');

        expect((bool) $affiliate->fresh()->has_historical_partner_links)->toBeFalse();

        makeLink($affiliate, $brand);

        expect((bool) $affiliate->fresh()->has_historical_partner_links)->toBeTrue();
    });

    it('stays true after a soft-delete (the row is tombstoned, not gone)', function () {
        $affiliate = makeProfessional('individual');
        $brand = makeProfessional('brand');
        $link = makeLink($affiliate, $brand);

        $link->delete();

        expect((bool) $affiliate->fresh()->has_historical_partner_links)->toBeTrue();
    });

    it('flips back to false when the last link is force-deleted', function () {
        $affiliate = makeProfessional('individual');
        $brand = makeProfessional('brand');
        $link = makeLink($affiliate, $brand);
        $link->delete();

        // PurgeSoftDeleted runs forceDelete() at end of retention.
        BrandPartnerLink::withTrashed()->find($link->id)->forceDelete();

        expect((bool) $affiliate->fresh()->has_historical_partner_links)->toBeFalse();
    });

    it('stays true if another active link still exists when one is force-deleted', function () {
        $affiliate = makeProfessional('individual');
        $brandA = makeProfessional('brand');
        $brandB = makeProfessional('brand');
        $linkA = makeLink($affiliate, $brandA);
        makeLink($affiliate, $brandB);

        $linkA->delete();
        BrandPartnerLink::withTrashed()->find($linkA->id)->forceDelete();

        expect((bool) $affiliate->fresh()->has_historical_partner_links)->toBeTrue();
    });
});

describe('AccountCapabilities — shows_ex_partner_panel reads denorm column', function () {
    it('is true for an individual with has_historical_partner_links=true', function () {
        // has_historical_partner_links is observer-maintained — not in $fillable,
        // so set it directly to bypass mass-assignment.
        $pro = new Professional(['account_type' => AccountType::Individual->value]);
        $pro->has_historical_partner_links = true;

        expect(AccountCapabilities::for($pro)->shows_ex_partner_panel)->toBeTrue();
    });

    it('is false for a fresh individual with no history', function () {
        $pro = new Professional(['account_type' => AccountType::Individual->value]);
        $pro->has_historical_partner_links = false;

        expect(AccountCapabilities::for($pro)->shows_ex_partner_panel)->toBeFalse();
    });

    it('is always false for brand and partner regardless of column value', function () {
        foreach (['brand', 'partner'] as $type) {
            $pro = new Professional(['account_type' => $type]);
            $pro->has_historical_partner_links = true;

            expect(AccountCapabilities::for($pro)->shows_ex_partner_panel)
                ->toBeFalse("account_type={$type} should not show ex-partner panel");
        }
    });
});

describe('Professional::brandPartnerLinksAll() relationship', function () {
    it('includes soft-deleted rows alongside active ones', function () {
        $affiliate = makeProfessional('partner');
        $brand = makeProfessional('brand');
        $linkA = makeLink($affiliate, $brand);
        $linkA->delete();
        $brandB = makeProfessional('brand');
        makeLink($affiliate, $brandB);

        expect($affiliate->fresh()->brandPartnerLinks()->count())->toBe(1);
        expect($affiliate->fresh()->brandPartnerLinksAll()->count())->toBe(2);
    });
});
