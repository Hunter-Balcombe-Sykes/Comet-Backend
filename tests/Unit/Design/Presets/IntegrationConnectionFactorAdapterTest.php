<?php

/**
 * Unit proof that a legacy per-connection DesignFactor reads IDENTICALLY when
 * driven through IntegrationConnectionFactorAdapter off the assembled
 * IdentityEvidence bag — the "shim" that lets the seven v1 factors keep their
 * exact detect(IntegrationConnection) bodies under the v2 engine. Uses
 * GoogleBusinessTypeFactor (a pure, network-free classifier) as the exemplar.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\CategoryStylePresets;
use App\Services\Design\Presets\Factors\GoogleBusinessTypeFactor;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\IntegrationConnectionFactorAdapter;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function adapterEvidence(array $connections): IdentityEvidence
{
    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        new Collection($connections),
    );
}

it('mirrors the wrapped factor key, priority, mode, and label', function () {
    $inner = new GoogleBusinessTypeFactor;
    $adapter = new IntegrationConnectionFactorAdapter($inner);

    expect($adapter->key())->toBe($inner->key())
        ->and($adapter->priority())->toBe($inner->priority())
        ->and($adapter->mode())->toBe($inner->mode())
        ->and($adapter->integrationLabel())->toBe($inner->integration());
});

it('produces the same detection off the evidence bag as the raw factor off the connection', function () {
    $connection = new IntegrationConnection([
        'user_id' => 'u1', 'platform' => 'google-business', 'payload' => ['category' => 'Barber shop'],
    ]);

    $direct = (new GoogleBusinessTypeFactor)->detect($connection);
    $viaAdapter = (new IntegrationConnectionFactorAdapter(new GoogleBusinessTypeFactor))
        ->detect(adapterEvidence([$connection]));

    expect($viaAdapter)->toBe($direct)
        ->and($viaAdapter)->toEqualCanonicalizing(
            CategoryStylePresets::forBucket(CategoryStylePresets::BEAUTY_PERSONAL_CARE),
        );
});

it('only reads connections of the wrapped factor platform', function () {
    $out = (new IntegrationConnectionFactorAdapter(new GoogleBusinessTypeFactor))->detect(adapterEvidence([
        new IntegrationConnection(['user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['businessCategory' => 'Gym']]),
        new IntegrationConnection(['user_id' => 'u1', 'platform' => 'google-business', 'payload' => ['category' => 'Restaurant']]),
    ]));

    // Only the google-business row is read -> food_drink bucket.
    expect($out)->toEqualCanonicalizing(CategoryStylePresets::forBucket(CategoryStylePresets::FOOD_DRINK));
});

it('returns empty when no connection matches the platform', function () {
    $out = (new IntegrationConnectionFactorAdapter(new GoogleBusinessTypeFactor))->detect(adapterEvidence([
        new IntegrationConnection(['user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['businessCategory' => 'Beauty']]),
    ]));

    expect($out)->toBe([]);
});
