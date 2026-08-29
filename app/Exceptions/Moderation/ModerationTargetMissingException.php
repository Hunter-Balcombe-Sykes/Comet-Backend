<?php

namespace App\Exceptions\Moderation;

use RuntimeException;

// Raised (and report()ed, never thrown) when a moderation enforcement job finds
// no target row to act on — the media/site/user the decision names does not
// exist (#W2-OBS-2). Distinct class so Nightwatch groups "enforcement hit
// nothing" separately from the generic job failures the same jobs can throw.
//
// It is REPORTED rather than THROWN on purpose: the enforcement jobs run as
// links in one Bus::chain, and a throw would halt the remaining takedown
// actions. See HasActionLogLifecycle::markFailed().
class ModerationTargetMissingException extends RuntimeException {}
