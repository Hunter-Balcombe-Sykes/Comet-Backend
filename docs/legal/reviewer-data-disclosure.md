# Privacy policy — third-party data disclosure (LEGAL-2)

> ⚠️ **Not legal advice.** This is an engineering-authored description of what the platform
> actually does with third-party personal data, written so a lawyer or a policy generator has
> accurate facts to work from. Have it reviewed before publishing. The *facts* below are verified
> against the code; the *characterisation* (APP 6, lawful basis) is for your adviser to confirm.

**Why this exists.** On 2026-07-30 the decision was taken to KEEP Google Business `reviews` on the
public wire (finding `271-PRIV-2`). That decision was made **conditional** on the privacy policy
disclosing it: this is the one place the platform publishes personal data about someone who is not
the account holder, and keeping it is only defensible if the policy says so. A generic template will
not cover this — templates describe data about *the user*, and this is data about *third parties*.

**Deadline:** before the first pilot customer signs. Tracked as `LEGAL-2 · P0` in
`docs/checklists/launch-readiness-checklist.md`.

**Slice 6 (2026-08-13) did not discharge LEGAL-2 — it inherited it and added to it.** The lane that
publishes reviewer data changed and the professional gained the ability to hide individual reviews;
the obligation to disclose, and the adviser questions in §4, are unchanged and now number five.

---

## 1. What is actually published — verified against code

When a professional connects Google Business and publishes their sitepage, each review republished
on that page carries, per review:

| Field | Content |
|---|---|
| `authorName` | The reviewer's Google display name |
| `authorUri` | A permanent link to the reviewer's Google contributor profile |
| `authorPhotoUrl` | The reviewer's Google profile photo |
| `rating` | Their star rating |
| `text` | Their verbatim review text |
| `reviewedAt` | When they wrote it |

**The lane changed on 2026-08-13 (slice 6); the field list did not.** Reviews reach the public wire
through the content pool — `PoolResolver::itemPayloads()` builds a `review` block on each item of
kind `review`, read from `content.f_review` — and are served at
`GET /api/public/profiles/{handle}` as `pools.reviews`. The legacy read at
`GoogleBusinessService.php:304-311`, which published the same data through
`GET …/integrations`, was retired in the same change.

`author_uri` was added to `content.f_review` deliberately so the retirement did not silently drop a
field this section lists as published. A change to what third-party data reaches the public wire is
a legal-review item, not a refactor; the point of the migration was that no such change occurred.

Two things follow from the new lane, both of them tightenings:

- `content.f_review` is the only copy of reviewer identity that reaches the page. The redaction
  scope, `content:prune-orphaned-review-pii` and the DSAR omission all govern that one table.
  Reviewer names previously also sat in `content.items.headline_cache` and `content.f_text.headline`,
  which none of those three reached; the projector no longer writes them and the existing copies
  were purged.
- Google's aggregate `rating`, `reviewCount` and review summary now live in `content.source_stats`
  and publish as `pools.reviews.stats`. Star average and review count are business facts about the
  professional; the summary is Google-authored prose derived from reviews, and is withheld from the
  professional's own data export alongside the reviewer fields.

Distribution characteristics that matter for the disclosure:

- The sitepage is **public and unauthenticated** — no login, no gate.
- It is **CDN-cached at the edge**, so copies persist beyond the origin response.
- The reviewers are **third parties** — the professional's customers. They never signed up to
  Partna, were never asked, and have no account through which to object.
- Republication is **on by default** for every claimed connection with reviews.

The same class of third-party identity data also reaches the wire from event platforms:
**Eventbrite and Humanitix `organiser` and `venue`** identity
(`DsarPayloadFilter::THIRD_PARTY_KEYS`). The policy should cover the category, not only Google.

## 2. What is deliberately NOT done — worth stating, it is the mitigation

- **Excluded from DSAR exports.** `DsarPayloadFilter` strips `reviews`, `reviewSummary`,
  `organiser` and `venue` from every data export, with an explicit user-facing explanation
  (`WITHHELD_DISCLOSURE`): this is personal data about *other people*, so it is not handed to the
  account holder on request. The nested `photos[].authors` leak was closed @ `31ccf162`.
- **Not published pre-claim.** Provisional (unclaimed) builds strip the identical data. Only a
  claimed, published site republishes it. **Re-verified against dev on 2026-08-13:** of 15 stored
  reviews, the 10 belonging to unclaimed accounts carry no name and no photo, and the 5 belonging to
  claimed accounts carry both. The stripping happens when the record is stored, not when it is read,
  so an unclaimed account's stored copy is permanently redacted — claiming does not retroactively
  restore attribution.
- The data is **fetched from Google's Places API**, not scraped, and is already public on Google.

## 3. Draft clause — for your adviser to review and adapt

> ### Information about other people
>
> Some information shown on a Partna site page is about people other than the site's owner.
>
> When a professional connects their Google Business profile, we display reviews that customers have
> left for that business on Google. Each review may include **the reviewer's name, their Google
> profile photo, a link to their Google contributor profile, their star rating, and the text of
> their review.** Where a professional connects an event platform such as Eventbrite or Humanitix,
> we may similarly display **the name and details of an event's organiser or venue.**
>
> This information is obtained from the relevant platform's public interface, where it has already
> been published by the person themselves. We display it on the professional's public site page so
> that visitors can see genuine, attributable feedback and event details. We do not alter it, and we
> do not combine it with other information to build a profile of the reviewer. **The professional
> can choose to hide individual reviews, or all of them, from their site page. They cannot edit a
> review, reorder them, or write one themselves — what is shown is shown as the reviewer wrote it,
> but it may not be everything the platform holds.**
>
> Partna does not hold an account for these individuals, and we do not use their information for
> any purpose other than displaying it as described. **If you are a reviewer or an event organiser
> and you do not want your information displayed on a Partna site page, contact us at
> [CONTACT] and we will remove it.** You can also remove or amend your review directly on the
> original platform, and the change will flow through to any Partna page displaying it.
>
> We do not include this third-party information in the data exports we provide to account holders,
> because it is personal information about other people rather than about the account holder.

## 4. Points to raise with the adviser

1. **APP 6 (use and disclosure for a secondary purpose)** — the reviewer gave the information to
   Google, not to Partna. Confirm the basis on which Partna re-discloses it, and whether "already
   public" is sufficient or whether the reasonable-expectations limb needs to be argued.
2. **GDPR** — if any reviewer is in the EU, confirm the lawful basis (likely legitimate interests)
   and whether a legitimate-interests assessment should be recorded.
3. **The removal route in §3 is a commitment.** It needs a real inbox and someone to action it.
   There is currently **no implemented takedown path for reviewer data** — removing a single review
   would today mean disconnecting the integration or waiting for the professional to. Either build
   it, or word the clause to match what actually happens.
4. **Default-on.** The strongest challenge to the current design is that republication is on by
   default rather than opt-in by the professional. Worth asking whether the adviser considers that
   defensible, or whether a per-connection toggle is the cheaper answer than the argument.
5. **Selective suppression cuts against §3's own justification.** Since 2026-08-13 the professional
   can hide any individual review from their site page (they cannot pin, reorder, edit or author
   one — the platform refuses all four). §3 justifies republishing a stranger's name and words on
   the grounds that visitors see "genuine, attributable feedback"; a set the professional has
   filtered is still genuine and still attributable, but it is no longer *representative*, and the
   reviewer whose criticism was hidden bears the cost of a display they never consented to. Two
   questions for the adviser: whether the §3 wording as drafted overstates what the page shows, and
   whether the fact that the set may be filtered needs disclosing to the site's **visitors** rather
   than only in the privacy policy. The clause above takes the more cautious route and says so;
   confirm that is the right side of the line, or that it is more than is needed.
