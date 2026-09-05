<?php

use App\Services\Media\MediaMirror;
use Tests\TestCase;

// base_path() below needs the app booted — the codebase's own convention for
// a Unit test that reaches it (see tests/Pest.php's note on this).
uses(TestCase::class)->in(__FILE__);

// One predicate, two reads. The reads MUST differ: MediaMirror asks about one
// asset from inside its job, where content.item_media is written; the
// projection writer asks at dispatch time, BEFORE those rows exist, so it has
// only the projection entries. What must not differ is the rule.

it('calls a role set with a video in it a video', function () {
    expect(MediaMirror::rolesIndicateVideo(['video']))->toBeTrue()
        ->and(MediaMirror::rolesIndicateVideo(['cover', 'video']))->toBeTrue();
});

it('calls image-only and empty role sets not-video', function () {
    expect(MediaMirror::rolesIndicateVideo(['cover', 'gallery']))->toBeFalse()
        ->and(MediaMirror::rolesIndicateVideo([]))->toBeFalse();
});

it('keeps the role decision in exactly one place', function () {
    // Drift insurance is the whole point of this unit — it has no live
    // failure. A behavioural test alone would not stop a third copy appearing,
    // so pin the source: the literal membership test lives in the rule only.
    // Single-quoted: "$roles" in a double-quoted string interpolates to ''.
    $ruleBody = 'return in_array(\'video\', $roles, true);';

    $offenders = [];
    foreach ([
        'app/Services/Media/MediaMirror.php',
        'app/Ingest/Projection/ProjectionWriter.php',
        'app/Jobs/Media/MirrorMediaAssetJob.php',
    ] as $rel) {
        foreach (file(base_path($rel)) as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === $ruleBody) {
                continue; // the rule itself
            }
            // A role test, not a value assignment: MediaMirror:183 builds the
            // string 'video' from a url shape and is legitimately not this.
            if (preg_match("/in_array\\(\\s*'video'|===\\s*'video'|'video'\\s*===/", $trimmed)) {
                $offenders[] = basename($rel).':'.($i + 1).' '.$trimmed;
            }
        }
    }

    expect($offenders)->toBe([], "A second image-vs-video decision has appeared:\n".implode("\n", $offenders));
});
