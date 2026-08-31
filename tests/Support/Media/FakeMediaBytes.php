<?php

namespace Tests\Support\Media;

/**
 * Real magic bytes for the fakes that stand in for CDN responses.
 *
 * Since #W2-SEC-14 the Instagram mirror byte-sniffs what actually landed on
 * disk rather than trusting the CDN's Content-Type, so a fake body of
 * `'img-bytes'` labelled `image/jpeg` is now correctly dropped — which is the
 * point of the fix, and useless as a happy-path fixture. These produce the
 * smallest bytes finfo will call a jpeg / png / mp4.
 *
 * A CLASS, not a Pest helper function: cross-file `function` helpers break
 * under --parallel, and six test files need these.
 */
final class FakeMediaBytes
{
    /** A real, decodable PNG of the given dimensions. */
    public static function png(int $width = 8, int $height = 8): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** A real, decodable JPEG of the given dimensions. */
    public static function jpeg(int $width = 8, int $height = 8): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * A minimal ISO-BMFF `ftyp` box — enough for finfo to answer `video/mp4`,
     * which is all the mirror asks of it (nothing decodes a reel our side).
     * Padded to $bytes so size-cap cases can still choose their own length.
     */
    public static function mp4(int $bytes = 0): string
    {
        return self::ftyp('isom', $bytes);
    }

    /**
     * The same box under an arbitrary 4-character major brand, so a test can
     * pick what libmagic will answer. Measured on this machine (libmagic 5.x):
     * isom/mp42/mp41/iso2/iso4/iso5/iso6/dash/avc1/mmp4 -> video/mp4,
     * 'qt  ' -> video/quicktime, 'M4V ' -> video/x-m4v, 3gp4 -> video/3gpp,
     * and msnv / cmfc -> application/octet-stream (libmagic knows neither).
     */
    public static function ftyp(string $brand, int $bytes = 0): string
    {
        $ftyp = pack('N', 24).'ftyp'.substr(str_pad($brand, 4), 0, 4).pack('N', 512).'isomiso2mp41';
        if ($bytes > strlen($ftyp)) {
            // `free` box: legal filler, so the result stays a well-formed mp4.
            $ftyp .= pack('N', $bytes - strlen($ftyp)).'free'.str_repeat("\0", $bytes - strlen($ftyp) - 8);
        }

        return $ftyp;
    }
}
