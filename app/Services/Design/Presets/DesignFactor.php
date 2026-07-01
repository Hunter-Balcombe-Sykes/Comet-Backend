<?php

namespace App\Services\Design\Presets;

use App\Models\Core\Site\IntegrationConnection;

/**
 * An integration-derived rule that contributes design-kit values. Concrete
 * factors (e.g. GoogleBusinessTypeFactor) are registered in DesignFactorRegistry
 * and evaluated by DesignPresetResolver on integration connect/refresh/disconnect.
 *
 * A factor may only target VALUE / SELECTION design-kit columns
 * (PresetTargetableColumns) — never inferred vars, which derive automatically.
 */
interface DesignFactor
{
    /** Stable identifier → contributions.source (provenance + the upsert unit). */
    public function key(): string;

    /** Platform slug this factor reads from (matches IntegrationConnection::$platform). */
    public function integration(): string;

    public function mode(): FactorMode;

    /** Higher wins a contested column. Default is the integration's base rank. */
    public function priority(): int;

    /**
     * Detect this factor's contribution from a connection's payload.
     *
     * @return array<string, string> sparse [design_kit column => value]; [] if the factor doesn't apply
     */
    public function detect(IntegrationConnection $connection): array;
}
