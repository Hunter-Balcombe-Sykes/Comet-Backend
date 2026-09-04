<?php

namespace App\Routing\Verification;

/**
 * For every brand that simply answers 404 for a page that does not exist.
 *
 * One class rather than five near-identical subclasses: the brands differ only
 * in which surface they are registered against, and the evidence for each is
 * recorded at that registration in LinkVerifier. A per-brand class here would
 * be five files whose bodies are all `{}`.
 *
 * Measured against the live sites on 2026-09-03, real page and fabricated id
 * side by side — a 404 on the fake alone is not evidence, because a brand that
 * 404s everything (or blocks us) would look identical:
 *
 *   quandoo   /place/ricks-place-92706 → 200   /place/not-a-place-99999999 → 404
 *   github    /torvalds                → 200   /zzz-not-a-real-user-…      → 404
 *   calendly  /acme                    → 200   /zzz-not-a-real-user-…      → 404
 *   youtube   /@Google                 → 200   /@zzznotarealchannel…       → 404
 *   x         /jack                    → 200   /zzz_not_real_…             → 404
 *
 * Two brands were TESTED AND REJECTED for this lane on the same day, and the
 * negative results matter more than the positive ones:
 *
 *   spotify   a fabricated artist id returns 200 with a 157 KB shell within
 *             60 bytes of the real one's. It is Class C, not Class A as an
 *             earlier note recorded. There is nothing here to read.
 *   resy      a plain fetch returns 200 and a byte-identical 5,177-byte SPA
 *             shell for both. It DOES answer 404 — but only to a crawler user
 *             agent, and our own honest bot string is not enough. Getting that
 *             answer means claiming to be Googlebot, which is a decision about
 *             how we present ourselves to other people's servers and not one
 *             to make silently inside an adapter.
 */
final class PlainNotFoundAdapter extends HttpVerificationAdapter {}
