<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Refiner factor (band 15, Auto): a business's operating RHYTHM is a weak but
 * real "what kind of place is this" signal that no category recipe captures. A
 * bar open late Friday/Saturday reads different from a solicitor open 9–5
 * weekdays. We read the structured weekly hours (IdentityEvidence::businessHours,
 * from site.workplaces.opening_hours) and nudge ONE-TO-TWO axes only — it's a
 * low-band refiner that any real category/archetype factor overrides.
 *
 * Rhythm → sparse nudge:
 *   late/weekend (nightlife, evening hospitality, entertainment) → higher energy:
 *       faster motion + bolder weight.
 *   strictly daytime weekday (professional services) → calmer: slower motion.
 * A mixed/unclear pattern, or no parseable hours, ABSTAINS (spec §4.1).
 *
 * CONFIDENCE GATE: needs a parseable weekly structure AND a clear rhythm (a decisive
 * late/weekend lean, or a decisive daytime-weekday-only lean). Anything ambiguous
 * degrades to [] — never a guess.
 *
 * SAFETY: hours can imply lifestyle; that inference is scored and DISCARDED inside
 * detect(). Only the resulting design VALUES persist — never the hours, never any
 * lifestyle label (spec §4.4).
 */
class HoursRhythmFactor implements EvidenceFactor
{
    public const SOURCE = 'hours-rhythm:energy';

    private const ENERGETIC = 'energetic';

    private const CALM = 'calm';

    /** A period is "late" when it runs at or past this hour (24h). */
    private const LATE_HOUR = 20; // 8pm

    /** Sparse overlays. Deliberately 1–2 axes — a low-band refiner, not a full look. */
    private const RHYTHM_TARGETS = [
        self::ENERGETIC => [
            'motion_pace' => 'fast',
            'weight_regular' => '500',
        ],
        self::CALM => [
            'motion_pace' => 'slow',
        ],
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'hours';
    }

    public function mode(): FactorMode
    {
        return FactorMode::Auto;
    }

    public function priority(): int
    {
        // Band 15 — just above platform-mix (12), below own-media (20). A weak
        // refiner; every category/archetype factor beats it.
        return 15;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $hours = $evidence->businessHours();
        if ($hours === null) {
            return []; // no parseable weekly structure
        }

        $rhythm = $this->rhythmOf($hours);

        return $rhythm === null ? [] : self::RHYTHM_TARGETS[$rhythm];
    }

    /**
     * Classify a normalised weekly-hours map into a rhythm, or null (abstain).
     *
     * ENERGETIC: opens late (≥ LATE_HOUR) on 2+ days, OR is open on the weekend
     *            while running late at least once — a genuine evening/weekend lean.
     * CALM:      open ONLY on weekdays (Mon–Fri) AND never late — the classic
     *            daytime professional pattern.
     * Anything in between (weekday daytime + occasional late, weekend-open but all
     * daytime, sparse/irregular) is ambiguous → null.
     *
     * @param  array<string, list<array{open:string, close:string}>>  $hours
     */
    private function rhythmOf(array $hours): ?string
    {
        $weekend = ['sat', 'sun'];
        $lateDays = 0;
        $openOnWeekend = false;
        $anyWeekdayOpen = false;
        $anyLate = false;

        foreach ($hours as $day => $periods) {
            $dayRunsLate = false;
            foreach ($periods as $period) {
                $closeHour = (int) substr($period['close'], 0, 2);
                $openHour = (int) substr($period['open'], 0, 2);
                // "Runs late" if it closes at/after LATE_HOUR, or closes past
                // midnight (close <= open means it wraps into the next day).
                if ($closeHour >= self::LATE_HOUR || $closeHour < $openHour) {
                    $dayRunsLate = true;
                }
            }
            if ($dayRunsLate) {
                $lateDays++;
                $anyLate = true;
            }
            if (in_array($day, $weekend, true)) {
                $openOnWeekend = true;
            } else {
                $anyWeekdayOpen = true;
            }
        }

        // Energetic: a decisive evening/weekend lean.
        if ($lateDays >= 2 || ($openOnWeekend && $anyLate)) {
            return self::ENERGETIC;
        }

        // Calm: weekdays only, never late — the professional daytime pattern.
        if ($anyWeekdayOpen && ! $openOnWeekend && ! $anyLate) {
            return self::CALM;
        }

        return null; // ambiguous rhythm — abstain
    }
}
