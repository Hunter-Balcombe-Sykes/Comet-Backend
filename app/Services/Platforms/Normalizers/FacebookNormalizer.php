<?php

namespace App\Services\Platforms\Normalizers;

// Facebook link-only normalizer — ports the former FacebookController. Accept a
// bare handle, a facebook.com/<handle> vanity URL, or a facebook.com/profile.php
// or legacy /pages|people|groups|pg/<Name>/<id> link → {username, url}. The old
// controller always built an array, then 422'd when the username was empty AND
// the URL carried none of those reserved keywords; that decision is folded in
// here as a null return, so the generic controller's null-check reproduces the
// exact behavior.
class FacebookNormalizer
{
    // Path segments that are Facebook URL keywords, never a vanity name/id — the
    // first segment after facebook.com/ can be one of these on legacy Page/Group/
    // Profile links (G4-4: a naive parser previously stored "pages" itself as the
    // username). Skip past the keyword to the actual name/id segment instead.
    private const RESERVED_SEGMENTS = ['pages', 'people', 'groups', 'pg'];

    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $selection = $this->parse($input);
        $reserved = $selection['reserved'];
        unset($selection['reserved']);

        // Empty username is only valid when the URL itself carried no name/id at
        // all (e.g. a bare "/pages/" or "/profile.php" with no query) — genuinely
        // reserved-keyword links; anything else with no handle is junk input.
        if ($selection['username'] === '' && ! $reserved) {
            return null;
        }

        return $selection;
    }

    /**
     * @return array{username:string, url:string, reserved:bool}
     */
    private function parse(string $input): array
    {
        $s = trim($input);

        if (preg_match('~facebook\.com/(.+)$~i', $s, $m)) {
            $path = trim($m[1], '/');
            [$pathOnly, $query] = array_pad(explode('?', $path, 2), 2, '');
            $segments = array_values(array_filter(explode('/', $pathOnly), fn ($seg) => $seg !== ''));
            $first = strtolower($segments[0] ?? '');

            if ($first === 'profile.php') {
                // Numeric profile link — the id lives in the query string
                // (?id=…), not the path; no vanity handle to extract, so the id
                // itself becomes the username (better than leaving it empty).
                parse_str($query, $params);
                $id = isset($params['id']) && is_string($params['id']) ? $params['id'] : '';

                return ['username' => $id, 'url' => 'https://www.facebook.com/'.$path, 'reserved' => true];
            }

            if (in_array($first, self::RESERVED_SEGMENTS, true)) {
                // Legacy /pages/<Name>/<id> (also /people, /groups, /pg) — the
                // reserved keyword is never the handle; skip to the actual
                // name/id segment that follows it. Query stripped (tracking
                // params only — the id/name always lives in the path here).
                $username = $this->firstMeaningfulSegment(array_slice($segments, 1));

                return ['username' => $username, 'url' => 'https://www.facebook.com/'.$pathOnly, 'reserved' => true];
            }

            // Vanity URL — first path segment is the username; drop query/trailing path.
            $username = $segments[0] ?? '';

            return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username, 'reserved' => false];
        }

        // A facebook.com URL with no path (e.g. https://www.facebook.com/) — no handle.
        if (preg_match('~facebook\.com~i', $s)) {
            return ['username' => '', 'url' => $s, 'reserved' => false];
        }

        $username = ltrim($s, '@');

        return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username, 'reserved' => false];
    }

    /**
     * Among the segments following a reserved keyword, prefer the first
     * human-readable (non-numeric) name; fall back to the first numeric id when
     * that's all there is (still better than storing nothing). Empty when no
     * segments remain at all.
     *
     * @param  list<string>  $segments
     */
    private function firstMeaningfulSegment(array $segments): string
    {
        foreach ($segments as $segment) {
            if (! ctype_digit($segment)) {
                return $segment;
            }
        }

        return $segments[0] ?? '';
    }
}
