<?php

use App\Services\Design\Presets\Factors\OwnMediaAccentFactor;
use App\Services\Http\SafeUrlFetcher;

/**
 * Exercises the dominant-colour pipeline via the protected bytes seam — the
 * pixel math is the logic worth pinning; detect()'s DB lookup is trivial
 * Eloquent covered at the resolver level.
 */
function accentProbe(): object
{
    // The bytes seam never touches the fetcher — a ctor-less mock avoids
    // booting config in this unit context.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);

    return new class($fetcher) extends OwnMediaAccentFactor
    {
        public function fromBytes(string $bytes): ?string
        {
            return $this->extractAccentFromBytes($bytes);
        }
    };
}

function pngBytes(int $r, int $g, int $b): string
{
    $img = imagecreatetruecolor(64, 64);
    $color = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, 0, 0, 63, 63, $color);
    ob_start();
    imagepng($img);
    imagedestroy($img);

    return (string) ob_get_clean();
}

it('extracts a saturated brand colour from a logo', function () {
    $hex = accentProbe()->fromBytes(pngBytes(224, 49, 12));

    expect($hex)->not->toBeNull();
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    expect($r)->toBeGreaterThan(200)
        ->and($g)->toBeLessThan(80)
        ->and($b)->toBeLessThan(60);
});

it('contributes nothing for a greyscale mark', function () {
    expect(accentProbe()->fromBytes(pngBytes(40, 40, 40)))->toBeNull()
        ->and(accentProbe()->fromBytes(pngBytes(250, 250, 250)))->toBeNull();
});

it('survives junk bytes', function () {
    expect(accentProbe()->fromBytes('definitely-not-a-png'))->toBeNull();
});
