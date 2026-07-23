<?php

use App\Services\Cache\AiSpendBudget;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('claims a slot when under both the actor and global caps', function () {
    config()->set('partna.limits.ai_spend.global_daily_cap', 10);
    config()->set('partna.limits.ai_spend.actors.mistral_ocr', 10);

    expect(app(AiSpendBudget::class)->tryClaim('mistral_ocr'))->toBeTrue();
});

it('rejects once the per-actor daily cap is reached, without touching other actors', function () {
    config()->set('partna.limits.ai_spend.global_daily_cap', 100);
    config()->set('partna.limits.ai_spend.actors.mistral_ocr', 2);
    config()->set('partna.limits.ai_spend.actors.deepseek_structure', 100);

    $budget = app(AiSpendBudget::class);
    expect($budget->tryClaim('mistral_ocr'))->toBeTrue()
        ->and($budget->tryClaim('mistral_ocr'))->toBeTrue()
        ->and($budget->tryClaim('mistral_ocr'))->toBeFalse()
        // A different actor's own cap is untouched by mistral_ocr's exhaustion.
        ->and($budget->tryClaim('deepseek_structure'))->toBeTrue();
});

it('rejects once the global daily cap is reached, even with actor headroom left', function () {
    config()->set('partna.limits.ai_spend.global_daily_cap', 1);
    config()->set('partna.limits.ai_spend.actors.mistral_ocr', 100);

    $budget = app(AiSpendBudget::class);
    expect($budget->tryClaim('mistral_ocr'))->toBeTrue()
        ->and($budget->tryClaim('mistral_ocr'))->toBeFalse();
});

it('rejects an unconfigured actor (zero-cap default)', function () {
    config()->set('partna.limits.ai_spend.global_daily_cap', 100);

    expect(app(AiSpendBudget::class)->tryClaim('unknown_actor'))->toBeFalse();
});
