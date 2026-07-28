<?php

namespace App\Services\Profile;

use App\Models\Core\Site\FieldBinding;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The §14 resolution path: identity-stream contributions fold onto the
 * workplace through per-field priority bindings instead of a hard-coded
 * account-type branch.
 *
 * TWO GATES, both pre-write, both fail-closed (§14):
 *   1. platform-side — the (site, field, source) binding must exist and be
 *      enabled. No row, no write; a disabled row keeps its priority but
 *      writes nothing.
 *   2. field-side — a `manual` binding row for the field is the LOCK (C2:
 *      the row is the lock; priority 0 is manual's reserved seat). Locked
 *      fields are untouchable by every automated source, whatever its mode.
 *
 * Between unlocked sources, priority + provenance decide: a field stamped by
 * a strictly higher-precedence (lower-priority-number) source is not
 * displaced; equal or lower precedence yields to `mode` — overwrite writes,
 * fill_blank only fills a blank.
 *
 * The SECTOR LAW is carried verbatim from IdentitySync (§14 requires it
 * word-for-word): sector lives on core.users with its own provenance;
 * any non-Google sector_source is permanent (first non-Google source wins,
 * manual included); Google may overwrite only a blank sector or one it
 * stamped itself, and only when its standing is `overwrite` — fill_blank
 * sources fill blanks regardless of source.
 *
 * SCOPE (WAVE-2C): this is the substrate + resolution path. The pre-account
 * pipeline swap that makes ingest Identity streams call this instead of
 * IdentitySync is a later queue item — until then IdentitySync remains the
 * live writer and this class's callers are the new-pipeline seams.
 */
class FieldBindingResolver
{
    /**
     * Fold one source's field candidates onto the user's workplace + user
     * mirrors, under the bindings' law.
     *
     * @param  array<string, mixed>  $candidates  field => value; null values skipped
     * @return list<string> fields actually written
     */
    public function apply(User $user, string $sourceKey, array $candidates): array
    {
        $site = $user->site;
        if (! $site instanceof Site) {
            return [];
        }

        $candidates = array_filter($candidates, fn ($v) => $v !== null);
        if ($candidates === []) {
            return [];
        }

        $bindings = FieldBinding::query()
            ->where('site_id', (string) $site->id)
            ->whereIn('field', array_keys($candidates))
            ->get()
            ->groupBy('field');

        $written = [];

        DB::connection($site->getConnectionName())->transaction(function () use ($site, $sourceKey, $candidates, $bindings, &$written) {
            // Locked re-read (LIFE-108 carried): the blank-check below is the
            // check half of a check-then-write; without the lock two folds can
            // both read stale-blank and both "win".
            $workplace = Workplace::query()->where('site_id', (string) $site->id)->lockForUpdate()->first()
                ?? new Workplace(['site_id' => (string) $site->id]);

            $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
            $stamp = now()->toIso8601String();
            $changed = false;

            foreach ($candidates as $field => $value) {
                $fieldBindings = $bindings->get($field, collect());

                $decision = $this->decide($fieldBindings, $sourceKey, $sources[$field]['source'] ?? null);
                if ($decision === 'skip') {
                    continue;
                }

                if ($decision === 'fill_blank' && ! $this->isBlank($workplace->{$field})) {
                    continue;
                }

                $workplace->{$field} = $value;
                $sources[$field] = ['source' => $sourceKey, 'at' => $stamp];
                $written[] = $field;
                $changed = true;
            }

            if ($changed) {
                $workplace->field_sources = $sources;
                $workplace->save();
            }
        });

        return $written;
    }

    /**
     * Sector capture path — the law verbatim (see class docblock). `standing`
     * comes from the caller's binding mode for the sector-bearing source:
     * overwrite for a full-sync account, fill_blank otherwise.
     */
    public function applySector(User $user, string $sourceKey, ?string $mappedSector, string $standing): void
    {
        if ($mappedSector === null) {
            return;
        }

        DB::connection($user->getConnectionName())->transaction(function () use ($user, $sourceKey, $mappedSector, $standing) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return;
            }

            $currentSource = $this->normalise($fresh->sector_source);

            if ($standing === 'overwrite') {
                // Any other source's stamped value is permanent — first
                // non-Google (non-self) source to write wins. The
                // `sector !== null` leg is defensive: a stamped-but-cleared
                // row must not block the fill forever. (IdentitySync,
                // verbatim.)
                if ($currentSource !== null && $currentSource !== $this->normalise($sourceKey) && $fresh->sector !== null) {
                    return;
                }

                if ($fresh->sector !== $mappedSector) {
                    $fresh->sector = $mappedSector;
                    $fresh->sector_source = $sourceKey;
                    $fresh->save();
                }

                return;
            }

            // fill_blank: only when nothing is set yet — inherently safe
            // regardless of the current source. (IdentitySync, verbatim.)
            if ($this->isBlank($fresh->sector)) {
                $fresh->sector = $mappedSector;
                $fresh->sector_source = $sourceKey;
                $fresh->save();
            }
        });

        $user->refresh();
    }

    /**
     * The two gates + priority contest for one field.
     *
     * @param  Collection<int, FieldBinding>  $fieldBindings
     * @return 'overwrite'|'fill_blank'|'skip'
     */
    private function decide($fieldBindings, string $sourceKey, ?string $stampedBy): string
    {
        /** @var FieldBinding|null $own */
        $own = $fieldBindings->first(
            fn (FieldBinding $b) => $this->normalise($b->source_key) === $this->normalise($sourceKey)
        );

        // Gate 1: no binding, or a disabled one — no write. Fail closed.
        if ($own === null || ! $own->is_enabled) {
            return 'skip';
        }

        // Gate 2: a manual row for this field is the lock. Its presence alone
        // decides — enabled or not, the user's row outranks every machine.
        $locked = $fieldBindings->contains(
            fn (FieldBinding $b) => $b->source_key === FieldBinding::SOURCE_MANUAL
        );
        if ($locked) {
            return 'skip';
        }

        // Priority contest: a field stamped by a strictly higher-precedence
        // source is not displaced by this one.
        if ($stampedBy !== null && $this->normalise($stampedBy) !== $this->normalise($sourceKey)) {
            /** @var FieldBinding|null $holder */
            $holder = $fieldBindings->first(
                fn (FieldBinding $b) => $this->normalise($b->source_key) === $this->normalise($stampedBy)
            );

            if ($holder !== null && $holder->priority < $own->priority) {
                return 'skip';
            }
        }

        return $own->mode === 'overwrite' ? 'overwrite' : 'fill_blank';
    }

    /**
     * Legacy field_sources stamps used hyphens ('google-business'); the §4
     * vocabulary uses underscores — one spelling for every comparison.
     */
    private function normalise(?string $sourceKey): ?string
    {
        return $sourceKey === null ? null : str_replace('-', '_', $sourceKey);
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) ? trim($value) === '' : false;
    }
}
