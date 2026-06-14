- [ ] **TEST-1** · P2 — CasePolicy methods lack any functional test
    - **Where:** app/Policies/CasePolicy.php:14‑35
    - **Affects:** Moderation staff operations – no safety net if a refactor breaks staff‑only access.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Policies/CasePolicyTest.php` with Pest tests for each public method (`viewAny`, `view`, `triage`, `take`, `release`, `decide`, `escalate`), asserting allowed for staff and denied for non‑staff.
        - Include a non‑staff actor test that expects a 404 via `denyAsNotFound`.
    - **Technical:** The policy class has seven methods that all return `true` for a `PartnaStaff` instance. There is no test that exercises any of them; the structural sweep (`ModerationPolicyCoverageTest`) only confirms registration, not correctness. A refactor that accidentally drops the `instanceof` check would silently open the gates.
    - **Plain English:** The rulebook for the moderation team says “staff only,” but nobody has ever walked through each rule to see if it actually keeps non‑staff out. It’s like a club with a bouncer at the door but no one has ever tried to walk in without showing ID.
    - **Evidence:**
        ```php
        // app/Policies/CasePolicy.php
        public function viewAny(User|PartnaStaff $actor): bool { return $actor instanceof PartnaStaff; }
        public function view(User|PartnaStaff $actor, ModerationCase $case): bool { return $actor instanceof PartnaStaff; }
        public function triage(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function take(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function release(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function decide(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        public function escalate(PartnaStaff $staff, ModerationCase $case): bool { return true; }
        ```
        No test file matching `CasePolicy` in the provided `tests/Feature/Security/PolicyEnforcement/` or any other file.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-2** · P2 — DecisionPolicy abilities are untested
    - **Where:** app/Policies/DecisionPolicy.php:12‑22
    - **Affects:** Staff decision viewing and reversal – regression would break audit integrity.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Policies/DecisionPolicyTest.php` with two tests: `view` allowed for staff, `reverse` allowed for staff, and denial for non‑staff actors.
    - **Technical:** Both `view` and `reverse` unconditionally return `true` for a `PartnaStaff`. Without a test, a future tightening of the policy could mistakenly block staff without detection. The structural `ModerationPolicyCoverageTest` only ensures registration, not behavior.
    - **Plain English:** The decisions filed by the moderation team have their own set of rules, but those rules have never been checked. It’s like a filing cabinet with a lock that nobody has ever turned.
    - **Evidence:**
        ```php
        // app/Policies/DecisionPolicy.php
        public function view(PartnaStaff $staff, Decision $decision): bool { return true; }
        public function reverse(PartnaStaff $staff, Decision $decision): bool { return true; }
        ```
        No test file for `DecisionPolicy` in the provided test set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-3** · P2 — FeatureFlagPolicy has no test confirming its deny‑all stance
    - **Where:** app/Policies/FeatureFlagPolicy.php:17‑27
    - **Affects:** Defensive layer – if a future route accidentally drops the staff middleware, a missing test could allow professionals to manage flags.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Policies/FeatureFlagPolicyTest.php` verifying that `viewAny`, `view`, and `manage` all return `false` for a professional actor (User).
    - **Technical:** The policy is intentionally deny‑all for User actors; the real authorization is via the `staff` middleware. A test ensures that a misconfigured route that skips the middleware still cannot grant access, fulfilling the defense‑in‑depth promised by the policy.
    - **Plain English:** The feature‑flag locker says “staff only – everyone else keep out,” but we’ve never checked that a regular user actually gets turned away. It’s like a “employees only” sign with no lock.
    - **Evidence:**
        ```php
        // app/Policies/FeatureFlagPolicy.php
        public function viewAny(User $pro): bool { return false; }
        public function view(User $pro, FeatureFlag|FeatureFlagOverride $resource): bool { return false; }
        public function manage(User $pro, FeatureFlag|FeatureFlagOverride|null $resource = null): bool { return false; }
        ```
        No test file for `FeatureFlagPolicy` among the provided tests.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-4** · P2 — FeedbackPolicy abilities are not tested
    - **Where:** app/Policies/FeedbackPolicy.php:18‑47
    - **Affects:** User‑submitted feedback – owner isolation and the `can_submit_feedback` gate could regress.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/FeedbackPolicyTest.php` covering `view`, `create`, `delete`, and `viewAny` with owner‑allowed and non‑owner‑denied cases, plus a test that `create` is blocked when the capability is absent.
    - **Technical:** The policy enforces ownership checks and a capability gate. No test exercises these paths; a refactor that drops the `can_submit_feedback` check or the ownership comparison would go unnoticed. The pattern matches the existing policy enforcement tests for Customer.
    - **Plain English:** The feedback box rules say “only your own messages and only if you’re allowed,” but nobody has tried to peek at someone else’s feedback or to post when banned. It’s like a suggestion box with no lid.
    - **Evidence:**
        ```php
        // app/Policies/FeedbackPolicy.php
        public function view(User $actor, Feedback $feedback): bool|Response { … }
        public function create(User $actor, Feedback $skeleton): bool|Response { … }
        public function delete(User $actor, Feedback $feedback): bool|Response { … }
        public function viewAny(User $actor): bool { return true; }
        ```
        No test file for `FeedbackPolicy` in the provided test suite.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-5** · P2 — GdprPolicy has no test of its ownership gate
    - **Where:** app/Policies/GdprPolicy.php:15‑23
    - **Affects:** GDPR export/deletion status visibility – a refactor could expose requests to non‑owners.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Policies/GdprPolicyTest.php` testing `view` for allowed (owner) and denied (non‑owner → 404).
    - **Technical:** The policy’s only ability, `view`, compares `user_id` and returns `denyAsNotFound()` on mismatch. Without a test, a future change to the owner resolution logic (e.g., switching to a relationship) could break the denial without alert.
    - **Plain English:** The privacy‑request records have a strict “my eyes only” rule, but we’ve never verified that someone else’s request actually returns a “not found.” It’s like a confidential file drawer with no label.
    - **Evidence:**
        ```php
        // app/Policies/GdprPolicy.php
        public function view(User $actor, Model $resource): bool|Response {
            if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
                return $this->denyAsNotFound();
            }
            return true;
        }
        ```
        No test file for `GdprPolicy` among the provided tests.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-6** · P2 — IntegrationConnectionPolicy owner isolation is not tested
    - **Where:** app/Policies/IntegrationConnectionPolicy.php:15‑51
    - **Affects:** Platform connections (e.g., Instagram, Twitch) – cross‑tenant access could leak account data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Policies/IntegrationConnectionPolicyTest.php` with tests for `view`, `update`, `delete`, and `create` for allowed (owner) and denied (non‑owner → 404).
    - **Technical:** The policy resolves ownership via `getAttributes()['user_id']`. None of its four abilities are exercised by the provided test files; the policy is registered via `PolicyCoverageTest` but correctness is unchecked.
    - **Plain English:** Each professional’s linked accounts (like social media) have a gate that says “only the account owner,” but no one has tried to open someone else’s connection. It’s like a phone lock screen with no PIN.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationConnectionPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function update(User $actor, Model $resource): bool|Response { … }
        public function delete(User $actor, Model $resource): bool|Response { … }
        public function create(User $actor, Model $skeleton): bool|Response { … }
        ```
        No test file for `IntegrationConnectionPolicy` in the file set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-7** · P2 — PartnaStaffPolicy self‑service and admin gates are untested
    - **Where:** app/Policies/PartnaStaffPolicy.php:28‑69
    - **Affects:** Staff record management – a broken self‑edit lock could allow an admin to accidentally lock the org out.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Policies/PartnaStaffPolicyTest.php` covering `view` (admin sees all, support sees own, support denied for others → 404), `update` (admin allowed, support denied, self‑edit denied), and `delete` (similar).
        - Use `actingAsStaff` helper with appropriate roles.
    - **Technical:** The policy encodes important invariants: self‑edit and self‑delete are forbidden; support staff can only see their own record. None of these paths are tested. A refactor could inadvertently allow self‑promotion or support‑role escalation without CI feedback.
    - **Plain English:** The rules for managing staff accounts include a safety net — an admin can’t accidentally fire themselves or change their own role. But that net has never been tested. It’s like an emergency brake that nobody has pulled.
    - **Evidence:**
        ```php
        // app/Policies/PartnaStaffPolicy.php
        public function view(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        public function update(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        public function delete(PartnaStaff $actor, PartnaStaff $target): bool|Response { … }
        ```
        No test file for `PartnaStaffPolicy` in the provided set.
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-8** · P2 — NotificationPolicy `view`, `update`, and `delete` abilities are untested
    - **Where:** app/Policies/NotificationPolicy.php:21‑48
    - **Affects:** Notification read/update – only mark‑read and dismiss (which likely exercise `view`/`update` under the hood) have tests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `tests/Feature/Security/PolicyEnforcement/NotificationPolicyEnforcementTest.php` to include explicit tests for `view`, `update`, and `delete` on both targeted and global notifications, verifying 404 for non‑owners and 423 for pending‑deletion.
    - **Technical:** The provided test only covers `markRead` and `dismiss` via the controller. The policy also defines `view` (which may be used by other endpoints), `update`, and `delete`. Without tests for those abilities, a refactor could break ownership checks on new notification endpoints without detection.
    - **Plain English:** The notification rules have several different permissions (“see”, “change”, “remove”), but we’ve only checked two of them. It’s like a bank vault with three locks but we only tested two keys.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function update(User $actor, Model $resource): bool|Response { … }
        public function delete(User $actor, Model $resource): bool|Response { … }
        ```
        The existing test file `tests/Feature/Security/PolicyEnforcement/NotificationPolicyEnforcementTest.php` only exercises paths reachable via `markRead` and `dismiss` controller actions.
    - `[DRAFT, confidence: 0.8]`

- [ ] **TEST-9** · P2 — UserSelfPolicy staff abilities and `view` are untested
    - **Where:** app/Policies/UserSelfPolicy.php:74‑101
    - **Affects:** Staff‑side user management – missing tests for `staffManage`, `staffForceDelete`, `staffBulkManage`, and the simple `view` self‑service ability.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add tests to `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php` (or a new file) covering:
            - `view` for allowed (own profile) and denied (other professional → 404).
            - `staffManage`, `staffForceDelete`, `staffBulkManage` for admin allowed and support denied.
    - **Technical:** The policy already enforces admin‑only for staff write operations and owner‑only for self‑service `view`. The provided test only covers `update` and deletion confirm. Staff‑side abilities are not exercised; a future change that weakens the admin gate would pass CI unnoticed.
    - **Plain English:** The rules for staff managing user accounts include special restrictions — only admins can permanently delete or bulk‑update. But those restrictions have never been put to the test. It’s like having a “manager’s keycard only” door without ever checking if a regular employee can walk through.
    - **Evidence:**
        ```php
        // app/Policies/UserSelfPolicy.php
        public function view(User $actor, Model $resource): bool|Response { … }
        public function staffManage(PartnaStaff $actor, User $target): bool { return $actor->isAdmin(); }
        public function staffForceDelete(PartnaStaff $actor, User $target): bool { return $actor->isAdmin(); }
        public function staffBulkManage(PartnaStaff $actor): bool { return $actor->isAdmin(); }
        ```
        `tests/Feature/Security/PolicyEnforcement/UserSelfPolicyEnforcementTest.php` only tests `update` and deletion confirm; no staff‑actor tests are present.
    - `[DRAFT, confidence: 0.9]`
