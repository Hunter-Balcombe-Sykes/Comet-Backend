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

---

## 1. What is actually published — verified against code

When a professional connects Google Business and publishes their sitepage, each review republished
on that page carries, per review (`GoogleBusinessService.php:304-311`):

| Field | Content |
|---|---|
| `author` | The reviewer's Google display name |
| `authorUri` | A permanent link to the reviewer's Google contributor profile |
| `authorPhoto` | The reviewer's Google profile photo |
| `rating` | Their star rating |
| `text` | Their verbatim review text |
| `publishedAgo`, `publishTime` | When they wrote it |

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
  claimed, published site republishes it.
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
> do not combine it with other information to build a profile of the reviewer.
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
