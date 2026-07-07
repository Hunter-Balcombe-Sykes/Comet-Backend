<?php

namespace App\Services\Design\Presets;

/**
 * A v2 design factor that reasons over the WHOLE assembled IdentityEvidence bag
 * (every active connection + user/workplace fields) rather than a single
 * IntegrationConnection payload. This is what makes cross-source factors
 * possible — platform mix, store price point, aesthetic expression — where the
 * conclusion depends on more than one connection or on user-level state.
 *
 * The v1 per-connection DesignFactor and SiteDesignFactor interfaces stay as
 * they are; the seven shipped factors keep their signatures. DesignPresetResolver
 * runs evidence factors in their own loop (assembling IdentityEvidence ONCE per
 * resolve), then adapts the legacy factors onto the same bag via
 * IntegrationConnectionFactorAdapter, so every factor ultimately reads one
 * assembled source.
 *
 * A factor may only target VALUE / SELECTION design-kit columns
 * (PresetTargetableColumns) — never inferred vars, which derive automatically.
 */
interface EvidenceFactor
{
    /** Stable identifier → contributions.source (provenance + the sweep/upsert unit). */
    public function key(): string;

    /** Plain-text contributions.integration label (provenance only). */
    public function integrationLabel(): string;

    public function mode(): FactorMode;

    /** Higher wins a contested column. Bands are defined in the factors-engine spec §4. */
    public function priority(): int;

    /**
     * @return array<string, string> sparse [design_kit column => value]; [] when nothing applies
     */
    public function detect(IdentityEvidence $evidence): array;
}
