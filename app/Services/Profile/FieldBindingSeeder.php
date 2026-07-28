<?php

namespace App\Services\Profile;

use App\Models\Core\Site\FieldBinding;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a site's field bindings from its preset (plan §14).
 *
 * The IdentitySync LAW, made rows: the business preset gives Google Business
 * `overwrite` standing on every identity field (GBP priority wins); the
 * partna preset gives it `fill_blank` (GBP fills blanks). Which preset
 * applies is read through AccountCapabilities — the same
 * `google_business_full_sync` boolean IdentitySync branches on today, so the
 * seeded rows and the legacy code path can never disagree about the law
 * while both exist.
 *
 * Idempotent and NON-DESTRUCTIVE: an existing (site, field, source) row is
 * left exactly as it stands — a user who disabled a platform's identity feed
 * (is_enabled=false) keeps that choice through every re-instantiation.
 */
class FieldBindingSeeder
{
    /** The ingest source key for Google Business (§4 vocabulary). */
    public const SOURCE_GOOGLE_BUSINESS = 'google_business';

    /** Default standing for a preset-seeded platform binding. */
    private const PLATFORM_PRIORITY = 10;

    /**
     * The workplace identity fields the bindings govern — exactly the set
     * IdentitySync folds today. A field outside this list has no binding and
     * therefore no automated writer (the resolver fails closed).
     *
     * @var list<string>
     */
    public const FIELDS = [
        'name',
        'address_line1',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
        'website',
        'category',
        'latitude',
        'longitude',
        'opening_hours',
    ];

    /** @return list<string> the (field) rows actually created */
    public function seed(Site $site): array
    {
        $user = $site->user;
        if (! $user instanceof User) {
            return [];
        }

        // The law's fork, read where the law lives (never raw account_type).
        $mode = AccountCapabilities::for($user)->google_business_full_sync
            ? 'overwrite'
            : 'fill_blank';

        $existing = FieldBinding::query()
            ->where('site_id', (string) $site->id)
            ->where('source_key', self::SOURCE_GOOGLE_BUSINESS)
            ->pluck('field')
            ->flip();

        $created = [];
        $now = now();
        $rows = [];

        foreach (self::FIELDS as $field) {
            if (isset($existing[$field])) {
                continue;
            }

            $rows[] = [
                'id' => (string) Str::uuid(),
                'site_id' => (string) $site->id,
                'field' => $field,
                'source_key' => self::SOURCE_GOOGLE_BUSINESS,
                'priority' => self::PLATFORM_PRIORITY,
                'mode' => $mode,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $created[] = $field;
        }

        if ($rows !== []) {
            DB::connection('pgsql')->table('site.field_bindings')->insert($rows);
        }

        return $created;
    }
}
