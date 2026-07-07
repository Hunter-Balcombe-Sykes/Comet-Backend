<?php

/**
 * Unit tests for AestheticExpressionFactor — the confidence-gated soft/neutral/
 * bold expression reading. Two responsibilities under test: (1) the
 * concordance-gated inference (≥2 agreeing signals or abstain), and (2) the
 * SAFETY CONTRACT — the factor must never compute or persist anything
 * demographic; only a soft/neutral/bold design recipe may ever leave it.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\AestheticExpressionFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\PresetTargetableColumns;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** IdentityEvidence over one Instagram connection carrying $payload (or none). */
function aeEvidence(?array $igPayload): IdentityEvidence
{
    $connections = new Collection;
    if ($igPayload !== null) {
        $connections->push(new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'instagram', 'payload' => $igPayload,
        ]));
    }

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
    );
}

it('concludes SOFT when category and name both lean soft', function () {
    $out = (new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Petal & Bloom Skincare',
    ]));

    expect($out['color_bg'])->toBe('#faf6f7')
        ->and($out['border_radius'])->toBe('1.5rem')
        ->and($out['weight_regular'])->toBe('300')
        ->and($out['effect_shadow_style'])->toBe('soft');
});

it('concludes BOLD when category and name both lean bold', function () {
    $out = (new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Gym/Physical Fitness Center',
        'fullName' => 'Iron Forge Strength',
    ]));

    expect($out['color_bg'])->toBe('#ffffff')
        ->and($out['border_radius'])->toBe('0.25rem')
        ->and($out['weight_regular'])->toBe('600')
        ->and($out['effect_shadow_style'])->toBe('hard')
        ->and($out['effect_style'])->toBe('bold');
});

it('abstains when the two signals disagree (soft vs bold)', function () {
    // Category leans soft, name leans bold — no concordance -> no contribution.
    expect((new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Raw Iron Grit',
    ])))->toBe([]);
});

it('abstains on a single soft signal (name neutral)', function () {
    // Only the category leans; the name is neutral -> under the ≥2 concordance bar.
    expect((new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Jordan Smith',
    ])))->toBe([]);
});

it('abstains when both signals are neutral', function () {
    expect((new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Consultant',
        'fullName' => 'Jordan Smith',
    ])))->toBe([]);
});

it('abstains when there is no Instagram connection at all', function () {
    expect((new AestheticExpressionFactor)->detect(aeEvidence(null)))->toBe([]);
});

it('abstains when the Instagram payload has neither category nor name', function () {
    expect((new AestheticExpressionFactor)->detect(aeEvidence(['username' => 'someone'])))->toBe([]);
});

// ── Safety contract (spec §9) ────────────────────────────────────────────────

it('only ever emits whitelisted design-kit recipe columns — never a demographic field', function () {
    $soft = (new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Beauty, Cosmetic & Personal Care',
        'fullName' => 'Petal & Bloom Skincare',
    ]));
    $bold = (new AestheticExpressionFactor)->detect(aeEvidence([
        'businessCategory' => 'Gym/Physical Fitness Center',
        'fullName' => 'Iron Forge Strength',
    ]));

    foreach ([$soft, $bold] as $out) {
        expect($out)->not->toBe([]);
        foreach (array_keys($out) as $column) {
            // Every emitted key is a legitimate design-kit target column …
            expect(PresetTargetableColumns::isValid($column))->toBeTrue();
            // … and NONE is a demographic/identity attribute.
            expect($column)->not->toContain('gender')
                ->and($column)->not->toContain('sex')
                ->and($column)->not->toContain('age');
        }
    }
});

it('never reasons about a gender: no such symbol exists in the factor CODE', function () {
    $source = file_get_contents(
        (new ReflectionClass(AestheticExpressionFactor::class))->getFileName(),
    );

    // Scan the CODE only (identifiers, strings, keywords) — comments are stripped
    // so the safety-contract docblock, which legitimately describes what the
    // factor must NOT do, doesn't trip the check. The claim under test: no
    // gender/demographic concept exists as a variable, constant, branch, or
    // string literal. The only thing this factor derives is soft/neutral/bold.
    $codeOnly = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= $token[1];
        } else {
            $codeOnly .= $token;
        }
    }

    $lower = strtolower($codeOnly);
    foreach (['gender', 'female', 'male', 'feminine', 'masculine', 'demographic'] as $forbidden) {
        expect(str_contains($lower, $forbidden))->toBeFalse("factor CODE must not mention '{$forbidden}'");
    }
});

it('is a one-shot factor in band E (declared)', function () {
    $factor = new AestheticExpressionFactor;
    expect($factor->priority())->toBe(64)
        ->and($factor->mode()->value)->toBe('one_shot')
        ->and($factor->key())->toBe('aesthetic-expression:lean');
});
