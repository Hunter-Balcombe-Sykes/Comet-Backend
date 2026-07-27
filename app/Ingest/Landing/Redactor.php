<?php

namespace App\Ingest\Landing;

/**
 * Strips declared paths BEFORE anything is stored (plan §4: redaction is a
 * product decision about what we are willing to hold, not a storage
 * optimisation — so it happens at landing, not at read).
 */
class Redactor
{
    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $paths  dot paths; `*` descends into every list element
     * @return array<string, mixed>
     */
    public static function apply(array $doc, array $paths): array
    {
        foreach ($paths as $path) {
            $doc = self::strip($doc, explode('.', $path));
        }

        return $doc;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private static function strip(array $doc, array $segments): array
    {
        $head = array_shift($segments);
        if ($head === null) {
            return $doc;
        }

        if ($head === '*') {
            foreach ($doc as $k => $v) {
                if (is_array($v)) {
                    $doc[$k] = $segments === [] ? [] : self::strip($v, $segments);
                }
            }

            return $doc;
        }

        if (! array_key_exists($head, $doc)) {
            return $doc;
        }

        if ($segments === []) {
            unset($doc[$head]);

            return $doc;
        }

        if (is_array($doc[$head])) {
            $doc[$head] = self::strip($doc[$head], $segments);
        }

        return $doc;
    }
}
