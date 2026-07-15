<?php

namespace App\Services\Platforms\Normalizers;

// Facebook link-only normalizer — ports the former FacebookController. Accept a
// bare handle, a facebook.com/<handle> (or short fb.com/<handle>) vanity URL, a
// facebook.com/profile.php link, an l.facebook.com/lm.facebook.com click-through
// redirect shim, or a legacy /pages|people|groups|pg/<Name>/<id> link →
// {username, url}. The old controller always built an array, then 422'd when
// the username was empty AND the URL carried none of those reserved keywords;
// that decision is folded in here as a null return, so the generic
// controller's null-check reproduces the exact behavior.
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

        // l.facebook.com / lm.facebook.com are Facebook's own click-through
        // redirect shim — the path is always literally "l.php"; the REAL
        // target lives URL-encoded in the `u` query param (fix-round P3).
        // Must run BEFORE the generic facebook.com branch below, which would
        // otherwise match this same substring and treat "l.php" itself as
        // the vanity handle.
        if (preg_match('~l(?:m)?\.facebook\.com/l\.php(?:\?(.*))?~i', $s, $m)) {
            parse_str($m[1] ?? '', $params);
            $target = isset($params['u']) && is_string($params['u']) && $params['u'] !== '' ? $params['u'] : null;

            // Unwrap + re-parse the real target; with no usable `u` param
            // there's nothing to resolve to — reserved/unrecognized link
            // rather than storing the literal "l.php" segment as a fake
            // username.
            return $target !== null ? $this->parse($target) : ['username' => '', 'url' => $s, 'reserved' => true];
        }

        if (preg_match('~(?:facebook\.com|\bfb\.com)/(.+)$~i', $s, $m)) {
            $path = trim($m[1], '/');
            [$pathOnly, $query] = array_pad(explode('?', $path, 2), 2, '');
            $segments = array_values(array_filter(explode('/', $pathOnly), fn ($seg) => $seg !== ''));
            $first = strtolower($segments[0] ?? '');

            if ($first === 'profile.php') {
                // Numeric profile link — the id lives in the query string
                // (?id=…), not the path; no vanity handle to extract, so the id
                // itself becomes the username (better than leaving it empty).
                // parse_str already percent-decodes query values.
                parse_str($query, $params);
                $id = isset($params['id']) && is_string($params['id']) ? $params['id'] : '';

                return ['username' => $id, 'url' => 'https://www.facebook.com/'.$path, 'reserved' => true];
            }

            if (in_array($first, self::RESERVED_SEGMENTS, true)) {
                // Legacy /pages/<Name>/<id> (also /people, /groups, /pg) — the
                // reserved keyword is never the handle; skip to the actual
                // name/id segment that follows it. Query stripped (tracking
                // params only — the id/name always lives in the path here).
                // rawurldecode (fix-round P3): a percent-encoded unicode name
                // (e.g. "Caf%C3%A9-Ren%C3%A9") is stored decoded for display —
                // this doesn't touch `url` below, which uses the whole raw
                // $pathOnly independently.
                $username = rawurldecode($this->firstMeaningfulSegment(array_slice($segments, 1)));

                return ['username' => $username, 'url' => 'https://www.facebook.com/'.$pathOnly, 'reserved' => true];
            }

            // Vanity URL — first path segment is the username; drop query/
            // trailing path. rawurldecode (fix-round P3) on the STORED
            // username only — `url` keeps the original (still percent-encoded,
            // still a valid link) segment.
            $rawUsername = $segments[0] ?? '';

            return ['username' => rawurldecode($rawUsername), 'url' => 'https://www.facebook.com/'.$rawUsername, 'reserved' => false];
        }

        // A facebook.com/fb.com URL with no path (e.g. https://www.facebook.com/) — no handle.
        if (preg_match('~facebook\.com|\bfb\.com~i', $s)) {
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
