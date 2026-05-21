# MFA Frontend Implementation Plan

> **Sister plan to:** `docs/superpowers/plans/2026-05-18-mfa-foundation.md` (backend) — both repos must coordinate on the contract section below.
> **Target repo:** `github.com/hunterbalcombesykes/partna-frontend` (Hydrogen + React + `@supabase/supabase-js`).
> **Audience:** Frontend implementer, skilled with Hydrogen/Remix and React, may be new to Supabase Auth's MFA APIs.

**Goal:** Build the user-facing MFA experience — TOTP enrollment, factor management, step-up challenges, and login flow updates — wired against the backend foundation that ships separately.

**Architecture:** All factor lifecycle (enroll, challenge, verify, list) runs client-side via the Supabase JS SDK — the frontend never touches the backend for these. The backend's only MFA-related role on this side is (a) returning 401 with a machine-readable `code` when a route needs MFA, and (b) hosting `DELETE /api/account/mfa/factors/{id}` for the fresh-AAL2-gated unenroll flow. Frontend builds one API interceptor that recognizes the codes, opens a global step-up modal, and retries the original request after the session promotes to `aal2`.

**Tech stack:** React, Hydrogen (Remix), `@supabase/supabase-js` (≥ 2.x), TanStack Query or SWR (whatever the repo uses for server-state), Tailwind for styling.

---

## Backend contract (already specified in the backend plan)

Frontend depends on these exact response shapes. Do not invent new codes — the backend uses these strings verbatim.

| When | Backend returns | Frontend reaction |
|---|---|---|
| Staff route hit with `aal=aal1` JWT | `401 {"message":"MFA required","code":"mfa_required"}` | Open step-up modal; on success retry the request |
| Fresh-MFA route (unenroll) hit with stale `aal2` | `401 {"message":"Recent MFA verification required","code":"mfa_fresh_required"}` | Open step-up modal; on success retry |
| Successful unenroll | `200 {"ok":true}` | Refresh factor list, dismiss modal |
| Supabase Admin API fails during unenroll | `502 {"message":"Could not remove factor"}` | Surface error to user, keep modal open with retry |
| MFA Verification Hook brute-force rejection | Surfaced by Supabase JS SDK as a verify error with the rejection message | Show "Too many failed attempts. Try again in 5 minutes." |

**Endpoint frontend calls directly:** `DELETE /api/account/mfa/factors/{factorId}` (auth: Supabase JWT in `Authorization: Bearer ...` header, same as every other authenticated API call).

**Everything else is the Supabase JS SDK** — `supabase.auth.mfa.enroll`, `challenge`, `verify`, `listFactors`, `getAuthenticatorAssuranceLevel`.

---

## Decisions locked in (from the planning session — same as backend)

| Decision | Choice |
|---|---|
| Factor type at launch | **TOTP only** (no SMS, no WebAuthn yet) |
| Enforcement scope | **Staff mandatory; users optional** (users will hit AAL2 only on the unenroll-self-factor flow today, plus any routes Josh gates later) |
| Fresh-MFA TTL | **300s** general; **60s** for unenroll-self-factor |
| Max enrolled factors per user | **10** (Supabase default — no override) |
| Recovery story | **No recovery codes.** Encourage users to enroll a second TOTP factor on a different device as backup |
| Friendly name on enrollment | **Required**, free-form text input (e.g. "iPhone Google Auth", "Backup TOTP") |

## Open decisions for the frontend implementer

Surface these to Josh during implementation if they're not obvious from the UX context:

1. **Step-up modal: dismissible?** Recommendation: dismissible only via an explicit "Cancel" button that clearly says "cancel this action" — not via clicking outside or pressing ESC. Closing the modal abandons the original request and returns the user to where they were.
2. **Staff first-login enrollment gate.** Recommendation: dedicated `/account/security/enroll-required` page that staff users are redirected to on first login if they have no verified TOTP factor. Cannot be dismissed; cannot navigate elsewhere within the staff area until enrolled. Magic link/sign-out is the only escape.
3. **Multiple factor enrollment encouragement.** Recommendation: after enrolling the first factor, show a one-time banner: "Add a backup factor on a different device. If you lose your phone, your backup is your only way back in." Banner is dismissible but recurs every 30 days until a second factor is enrolled.
4. **Where the step-up modal lives in the component tree.** Recommendation: app-root level, controlled by a context provider (`MfaStepUpProvider`), exposed via `useMfaStepUp()` hook. Single global instance.

---

## File map (suggested — adapt to actual repo layout)

The repo's existing structure may differ; treat paths as illustrative and follow the established conventions where they conflict.

| Path | Responsibility |
|---|---|
| `app/lib/supabase/mfa.ts` | Typed wrappers around `supabase.auth.mfa.*` — single source of truth for the SDK contract |
| `app/hooks/useMfa.ts` | React hooks: `useFactors()`, `useAal()`, `useStepUp()` |
| `app/lib/api/client.ts` (or wherever the existing API client lives) | Add the 401-with-`code` interceptor |
| `app/components/mfa/StepUpModal.tsx` | Global step-up modal — challenge + verify a chosen factor |
| `app/components/mfa/StepUpProvider.tsx` | React context — `useMfaStepUp()` exposes `.openAndAwait()` |
| `app/components/mfa/EnrollTotpFlow.tsx` | QR code display + verify input — used inline on the security page |
| `app/routes/account.security.tsx` | Security settings page — list factors, add factor, remove factor |
| `app/routes/account.security.enroll-required.tsx` | Staff first-login enrollment gate |
| `app/routes/auth.callback.tsx` (or wherever post-login routing happens) | Check `aal` after login, prompt for step-up if `nextLevel === 'aal2'` |
| `app/lib/api/__tests__/interceptor.test.ts` | Tests for the 401 → step-up → retry flow |
| `app/components/mfa/__tests__/StepUpModal.test.tsx` | Component test |
| `app/components/mfa/__tests__/EnrollTotpFlow.test.tsx` | Component test |

---

## Task 0: Branch prep + Supabase client check

- [ ] **Step 1: Branch off main (or whatever the integration branch is)**

```bash
git fetch origin
git checkout main
git pull
git checkout -b feat/mfa-frontend
```

- [ ] **Step 2: Confirm `@supabase/supabase-js` is at a version that includes MFA**

```bash
grep '"@supabase/supabase-js"' package.json
```

Required: ≥ `2.16.0` (when MFA went stable). Recommended: latest 2.x. If older, upgrade in a separate commit at the start of this branch:

```bash
npm install @supabase/supabase-js@latest
```

- [ ] **Step 3: Verify the existing Supabase client export**

The repo should already have something like `app/lib/supabase/client.ts` (browser client) and `app/lib/supabase/server.ts` (server client). Confirm the browser client is the one used in client-side React components — that's the one we'll call `.auth.mfa.*` on.

---

## Task 1: Typed MFA wrapper module

**Files:**
- Create: `app/lib/supabase/mfa.ts`

> Why a wrapper instead of calling `supabase.auth.mfa.*` directly: Supabase's SDK returns `{data, error}` tuples everywhere. Wrapping them once gives the rest of the app a clean Promise-throws-or-returns-data interface, and it's the single place we'd touch if Supabase ever revs the API surface.

- [ ] **Step 1: Write `app/lib/supabase/mfa.ts`**

```typescript
import { supabase } from "./client";

export type Factor = {
  id: string;
  friendly_name?: string | null;
  factor_type: "totp" | "phone" | "webauthn";
  status: "verified" | "unverified";
  created_at: string;
  updated_at: string;
};

export type AalInfo = {
  currentLevel: "aal1" | "aal2" | null;
  nextLevel: "aal1" | "aal2" | null;
  currentAuthenticationMethods: Array<{ method: string; timestamp: number }>;
};

export type EnrollTotpResult = {
  factorId: string;
  qrCode: string;     // SVG data URL — render directly in <img src={qrCode}>
  secret: string;     // Manual entry fallback (for users whose phone can't scan)
  uri: string;        // otpauth:// URI — alternative manual entry
};

/**
 * Begin TOTP enrollment. Creates an *unverified* factor on Supabase.
 * Must be followed by challenge() + verify() within a reasonable
 * window — abandoned enrollments leave unverified rows that the user
 * can clean up via the factor list.
 */
export async function enrollTotp(friendlyName: string): Promise<EnrollTotpResult> {
  const { data, error } = await supabase.auth.mfa.enroll({
    factorType: "totp",
    friendlyName,
  });
  if (error) throw error;
  return {
    factorId: data.id,
    qrCode: data.totp.qr_code,
    secret: data.totp.secret,
    uri: data.totp.uri,
  };
}

/**
 * Challenge a factor. Returns a challenge id that the next verify()
 * call must reference.
 */
export async function challenge(factorId: string): Promise<{ challengeId: string; expiresAt: number }> {
  const { data, error } = await supabase.auth.mfa.challenge({ factorId });
  if (error) throw error;
  return { challengeId: data.id, expiresAt: data.expires_at };
}

/**
 * Verify a challenge with a 6-digit code. On success the session is
 * promoted to aal2 and all OTHER sessions for this user are revoked
 * (security property of Supabase MFA — documented behavior).
 */
export async function verify(factorId: string, challengeId: string, code: string): Promise<void> {
  const { error } = await supabase.auth.mfa.verify({ factorId, challengeId, code });
  if (error) throw error;
}

/** Convenience — challenge + verify in one call. */
export async function challengeAndVerify(factorId: string, code: string): Promise<void> {
  const { error } = await supabase.auth.mfa.challengeAndVerify({ factorId, code });
  if (error) throw error;
}

export async function listFactors(): Promise<Factor[]> {
  const { data, error } = await supabase.auth.mfa.listFactors();
  if (error) throw error;
  // Supabase returns { all, totp, phone } — we only care about TOTP today
  return data.totp as Factor[];
}

export async function getAal(): Promise<AalInfo> {
  const { data, error } = await supabase.auth.mfa.getAuthenticatorAssuranceLevel();
  if (error) throw error;
  return data as AalInfo;
}

/**
 * Whether the user has at least one verified factor enrolled.
 * Convenience for "should we show the staff enrollment gate?".
 */
export async function hasVerifiedFactor(): Promise<boolean> {
  const factors = await listFactors();
  return factors.some((f) => f.status === "verified");
}
```

- [ ] **Step 2: Commit**

```bash
git add app/lib/supabase/mfa.ts
git commit -m "feat(mfa): add typed wrappers for supabase.auth.mfa.*"
```

---

## Task 2: React hooks — `useFactors`, `useAal`

**Files:**
- Create: `app/hooks/useMfa.ts`

> Using the existing server-state library (TanStack Query / SWR). If the repo uses TanStack Query, the example below is correct. If it uses SWR, swap to `useSWR`/`useSWRMutation` with the same keys.

- [ ] **Step 1: Write `app/hooks/useMfa.ts`**

```typescript
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  listFactors,
  getAal,
  enrollTotp,
  challengeAndVerify,
  hasVerifiedFactor,
  type Factor,
  type AalInfo,
} from "~/lib/supabase/mfa";

const FACTORS_KEY = ["mfa", "factors"] as const;
const AAL_KEY = ["mfa", "aal"] as const;

export function useFactors() {
  return useQuery<Factor[]>({
    queryKey: FACTORS_KEY,
    queryFn: listFactors,
    staleTime: 30_000,
  });
}

export function useAal() {
  return useQuery<AalInfo>({
    queryKey: AAL_KEY,
    queryFn: getAal,
    // AAL doesn't change without a verify; refetch only on demand.
    staleTime: Infinity,
  });
}

export function useHasMfa() {
  return useQuery<boolean>({
    queryKey: ["mfa", "hasMfa"],
    queryFn: hasVerifiedFactor,
    staleTime: 30_000,
  });
}

export function useEnrollTotp() {
  return useMutation({
    mutationFn: (friendlyName: string) => enrollTotp(friendlyName),
  });
}

/**
 * Verify a freshly-enrolled factor. On success invalidates both the
 * factor list (so the new factor shows up as verified) and the AAL
 * info (so the session reflects aal2).
 */
export function useVerifyEnrollment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ factorId, code }: { factorId: string; code: string }) => {
      await challengeAndVerify(factorId, code);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: FACTORS_KEY });
      qc.invalidateQueries({ queryKey: AAL_KEY });
      qc.invalidateQueries({ queryKey: ["mfa", "hasMfa"] });
    },
  });
}
```

- [ ] **Step 2: Commit**

```bash
git add app/hooks/useMfa.ts
git commit -m "feat(mfa): add useFactors, useAal, useEnrollTotp hooks"
```

---

## Task 3: API interceptor for `mfa_required` / `mfa_fresh_required`

**Files:**
- Modify: the existing API client (path varies — likely `app/lib/api/client.ts` or `app/utils/fetcher.ts`)

> This is the central integration seam between the two repos. After this lands, *any* backend route can be promoted to AAL2-required and the frontend will handle the step-up automatically — no per-route changes needed.

- [ ] **Step 1: Locate the existing fetch wrapper**

Most likely a small module that adds the `Authorization: Bearer <jwt>` header and returns JSON. We extend it to recognize the MFA response codes.

- [ ] **Step 2: Add the interceptor logic**

Inside the fetch wrapper's response handler, before throwing on 4xx:

```typescript
import { openStepUpAndAwait } from "~/components/mfa/StepUpProvider";

export async function apiFetch<T>(url: string, init: RequestInit = {}, _retry = false): Promise<T> {
  const res = await fetch(url, withAuthHeader(init));

  if (res.status === 401 && !_retry) {
    const body = await res.clone().json().catch(() => ({}));

    if (body.code === "mfa_required" || body.code === "mfa_fresh_required") {
      // Open the global step-up modal; promise resolves when the user
      // completes a challenge (session promoted to aal2) or rejects
      // when the user cancels.
      try {
        await openStepUpAndAwait({
          reason: body.code,
          message: body.message,
        });
      } catch (cancelled) {
        // User dismissed the modal — surface the original 401 to the
        // caller so they can decide what to do (usually: go back to the
        // previous screen, since the action wasn't completed).
        throw new MfaRequiredError(body.message, body.code);
      }

      // Step-up succeeded — retry the original request exactly once.
      return apiFetch<T>(url, init, /* _retry */ true);
    }
  }

  if (!res.ok) {
    throw await buildError(res);
  }
  return res.json();
}

export class MfaRequiredError extends Error {
  constructor(public message: string, public code: "mfa_required" | "mfa_fresh_required") {
    super(message);
    this.name = "MfaRequiredError";
  }
}
```

The `_retry` flag prevents infinite loops if the backend gates a route on AAL2 but the verify completed against the wrong factor (shouldn't happen, but defense-in-depth).

`openStepUpAndAwait` is the public API of the StepUpProvider (Task 4) — imported from there but called outside React's render tree.

- [ ] **Step 3: Commit**

```bash
git commit -am "feat(mfa): handle 401 mfa_required / mfa_fresh_required with step-up retry"
```

---

## Task 4: Global step-up modal + provider

**Files:**
- Create: `app/components/mfa/StepUpProvider.tsx`
- Create: `app/components/mfa/StepUpModal.tsx`
- Modify: app root layout — wrap children in `<StepUpProvider>`

> Why a provider with an imperative `openAndAwait` API: the API interceptor (Task 3) is called from anywhere — server-state mutations, loader functions, deeply nested components. It needs to open the modal *without* having a React ref to it. The provider sets a module-level handle on mount, the interceptor calls into it directly.

- [ ] **Step 1: Write the provider**

```typescript
// app/components/mfa/StepUpProvider.tsx
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { StepUpModal } from "./StepUpModal";

type OpenArgs = {
  reason: "mfa_required" | "mfa_fresh_required";
  message?: string;
};

type ProviderApi = {
  open: (args: OpenArgs) => Promise<void>;
};

const StepUpContext = createContext<ProviderApi | null>(null);

// Module-level handle used by the API interceptor (which runs outside React).
let externalOpen: ((args: OpenArgs) => Promise<void>) | null = null;

export function openStepUpAndAwait(args: OpenArgs): Promise<void> {
  if (!externalOpen) {
    return Promise.reject(new Error("StepUpProvider not mounted"));
  }
  return externalOpen(args);
}

export function StepUpProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<{
    args: OpenArgs;
    resolve: () => void;
    reject: (e: unknown) => void;
  } | null>(null);

  const open = useCallback((args: OpenArgs): Promise<void> => {
    return new Promise((resolve, reject) => {
      setPending({ args, resolve, reject });
    });
  }, []);

  // Expose `open` to the module-level handle so non-React callers can reach it.
  useEffect(() => {
    externalOpen = open;
    return () => {
      externalOpen = null;
    };
  }, [open]);

  return (
    <StepUpContext.Provider value={{ open }}>
      {children}
      {pending && (
        <StepUpModal
          reason={pending.args.reason}
          message={pending.args.message}
          onSuccess={() => {
            pending.resolve();
            setPending(null);
          }}
          onCancel={() => {
            pending.reject(new Error("User cancelled step-up"));
            setPending(null);
          }}
        />
      )}
    </StepUpContext.Provider>
  );
}

export function useMfaStepUp() {
  const ctx = useContext(StepUpContext);
  if (!ctx) throw new Error("useMfaStepUp must be used inside StepUpProvider");
  return ctx;
}
```

- [ ] **Step 2: Write the modal**

```typescript
// app/components/mfa/StepUpModal.tsx
import { useState } from "react";
import { useFactors } from "~/hooks/useMfa";
import { challengeAndVerify } from "~/lib/supabase/mfa";

type Props = {
  reason: "mfa_required" | "mfa_fresh_required";
  message?: string;
  onSuccess: () => void;
  onCancel: () => void;
};

export function StepUpModal({ reason, message, onSuccess, onCancel }: Props) {
  const { data: factors, isLoading } = useFactors();
  const verified = (factors ?? []).filter((f) => f.status === "verified");

  const [selectedFactorId, setSelectedFactorId] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Auto-select the only factor if there's just one.
  if (selectedFactorId === null && verified.length === 1) {
    setSelectedFactorId(verified[0].id);
  }

  const submit = async () => {
    if (!selectedFactorId || code.length !== 6) return;
    setSubmitting(true);
    setError(null);
    try {
      await challengeAndVerify(selectedFactorId, code);
      onSuccess();
    } catch (e: any) {
      // Supabase surfaces the brute-force rejection message verbatim
      // from the MFA Verification Hook — display it as-is.
      setError(e?.message ?? "Verification failed. Try again.");
      setCode("");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold">
          {reason === "mfa_fresh_required"
            ? "Verify again to continue"
            : "Verify with MFA"}
        </h2>
        <p className="mt-2 text-sm text-gray-600">
          {message ??
            (reason === "mfa_fresh_required"
              ? "This action requires a recent MFA verification."
              : "Enter the 6-digit code from your authenticator app.")}
        </p>

        {isLoading ? (
          <p className="mt-4 text-sm">Loading factors…</p>
        ) : verified.length === 0 ? (
          <p className="mt-4 text-sm text-red-700">
            You have no verified MFA factors. Set one up in Account → Security.
          </p>
        ) : (
          <>
            {verified.length > 1 && (
              <select
                className="mt-4 w-full rounded border p-2"
                value={selectedFactorId ?? ""}
                onChange={(e) => setSelectedFactorId(e.target.value)}
              >
                <option value="" disabled>
                  Choose a factor…
                </option>
                {verified.map((f) => (
                  <option key={f.id} value={f.id}>
                    {f.friendly_name ?? "Authenticator"}
                  </option>
                ))}
              </select>
            )}
            <input
              autoFocus
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              placeholder="123456"
              className="mt-4 w-full rounded border p-2 text-center text-2xl tracking-widest"
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
              onKeyDown={(e) => {
                if (e.key === "Enter") submit();
              }}
              disabled={submitting}
            />
            {error && <p className="mt-2 text-sm text-red-700">{error}</p>}
          </>
        )}

        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            className="rounded px-4 py-2 text-gray-700 hover:bg-gray-100"
            onClick={onCancel}
            disabled={submitting}
          >
            Cancel
          </button>
          <button
            type="button"
            className="rounded bg-blue-600 px-4 py-2 text-white disabled:bg-blue-300"
            onClick={submit}
            disabled={submitting || !selectedFactorId || code.length !== 6}
          >
            {submitting ? "Verifying…" : "Verify"}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Mount the provider at app root**

In whatever file is the top-level layout (e.g. `app/root.tsx` for Remix), wrap the rendered children:

```typescript
import { StepUpProvider } from "~/components/mfa/StepUpProvider";

export default function App() {
  return (
    <html>
      <body>
        <StepUpProvider>
          <Outlet />
        </StepUpProvider>
      </body>
    </html>
  );
}
```

- [ ] **Step 4: Commit**

```bash
git add app/components/mfa/StepUpProvider.tsx app/components/mfa/StepUpModal.tsx app/root.tsx
git commit -m "feat(mfa): add global step-up modal + imperative provider"
```

---

## Task 5: TOTP enrollment flow component

**Files:**
- Create: `app/components/mfa/EnrollTotpFlow.tsx`

> The flow has three sub-states: enter friendly name → display QR + verify input → success. Each is its own conditional render inside one component.

- [ ] **Step 1: Write the component**

```typescript
// app/components/mfa/EnrollTotpFlow.tsx
import { useState } from "react";
import { useEnrollTotp, useVerifyEnrollment } from "~/hooks/useMfa";

type Props = {
  onComplete: () => void;
  onCancel?: () => void;
};

export function EnrollTotpFlow({ onComplete, onCancel }: Props) {
  const [step, setStep] = useState<"name" | "verify" | "done">("name");
  const [friendlyName, setFriendlyName] = useState("");
  const [enrollment, setEnrollment] = useState<{ factorId: string; qrCode: string; secret: string } | null>(null);
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);

  const enroll = useEnrollTotp();
  const verify = useVerifyEnrollment();

  const startEnroll = async () => {
    setError(null);
    try {
      const result = await enroll.mutateAsync(friendlyName.trim() || "Authenticator");
      setEnrollment(result);
      setStep("verify");
    } catch (e: any) {
      setError(e?.message ?? "Could not start enrollment");
    }
  };

  const submitCode = async () => {
    if (!enrollment) return;
    setError(null);
    try {
      await verify.mutateAsync({ factorId: enrollment.factorId, code });
      setStep("done");
    } catch (e: any) {
      setError(e?.message ?? "Code did not verify. Try again.");
      setCode("");
    }
  };

  if (step === "name") {
    return (
      <div className="space-y-4">
        <h3 className="text-base font-medium">Name your authenticator</h3>
        <p className="text-sm text-gray-600">
          Pick something you'll recognize (e.g. "iPhone Google Auth"). You can enroll more than one.
        </p>
        <input
          type="text"
          autoFocus
          maxLength={64}
          className="w-full rounded border p-2"
          value={friendlyName}
          onChange={(e) => setFriendlyName(e.target.value)}
          placeholder="iPhone Google Auth"
        />
        {error && <p className="text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-2">
          {onCancel && (
            <button type="button" onClick={onCancel} className="rounded px-4 py-2 text-gray-700 hover:bg-gray-100">
              Cancel
            </button>
          )}
          <button type="button" onClick={startEnroll} className="rounded bg-blue-600 px-4 py-2 text-white">
            Continue
          </button>
        </div>
      </div>
    );
  }

  if (step === "verify" && enrollment) {
    return (
      <div className="space-y-4">
        <h3 className="text-base font-medium">Scan with your authenticator app</h3>
        <img src={enrollment.qrCode} alt="TOTP QR code" className="mx-auto h-48 w-48" />
        <p className="text-center text-xs text-gray-500">
          Can't scan? Enter this secret manually:{" "}
          <code className="rounded bg-gray-100 px-1 py-0.5">{enrollment.secret}</code>
        </p>
        <p className="text-sm">After scanning, enter the 6-digit code your app shows.</p>
        <input
          inputMode="numeric"
          pattern="[0-9]*"
          maxLength={6}
          className="w-full rounded border p-2 text-center text-2xl tracking-widest"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
        />
        {error && <p className="text-sm text-red-700">{error}</p>}
        <button
          type="button"
          onClick={submitCode}
          disabled={code.length !== 6 || verify.isPending}
          className="w-full rounded bg-blue-600 px-4 py-2 text-white disabled:bg-blue-300"
        >
          {verify.isPending ? "Verifying…" : "Confirm"}
        </button>
      </div>
    );
  }

  if (step === "done") {
    return (
      <div className="space-y-4 text-center">
        <h3 className="text-base font-medium">MFA enabled</h3>
        <p className="text-sm text-gray-600">
          Your account is now protected. Add a second factor on a different device as a backup — it's the
          only way to regain access if you lose your phone.
        </p>
        <button type="button" onClick={onComplete} className="rounded bg-blue-600 px-4 py-2 text-white">
          Done
        </button>
      </div>
    );
  }

  return null;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/components/mfa/EnrollTotpFlow.tsx
git commit -m "feat(mfa): TOTP enrollment flow component"
```

---

## Task 6: Security settings page

**Files:**
- Create: `app/routes/account.security.tsx` (Remix file-based route)

- [ ] **Step 1: Write the page**

```typescript
// app/routes/account.security.tsx
import { useState } from "react";
import { useFactors } from "~/hooks/useMfa";
import { EnrollTotpFlow } from "~/components/mfa/EnrollTotpFlow";
import { useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "~/lib/api/client";

export default function SecurityPage() {
  const { data: factors, isLoading, refetch } = useFactors();
  const [showEnroll, setShowEnroll] = useState(false);
  const [removingId, setRemovingId] = useState<string | null>(null);
  const [removeError, setRemoveError] = useState<string | null>(null);
  const qc = useQueryClient();

  const verified = (factors ?? []).filter((f) => f.status === "verified");
  const pending = (factors ?? []).filter((f) => f.status === "unverified");

  const removeFactor = async (factorId: string) => {
    setRemovingId(factorId);
    setRemoveError(null);
    try {
      // Backend enforces fresh-AAL2 (60s); if the user's last verify
      // is older, the apiFetch interceptor opens the step-up modal
      // automatically and retries on success.
      await apiFetch(`/api/account/mfa/factors/${factorId}`, { method: "DELETE" });
      await refetch();
      qc.invalidateQueries({ queryKey: ["mfa", "aal"] });
      qc.invalidateQueries({ queryKey: ["mfa", "hasMfa"] });
    } catch (e: any) {
      setRemoveError(e?.message ?? "Could not remove factor");
    } finally {
      setRemovingId(null);
    }
  };

  return (
    <div className="mx-auto max-w-2xl py-8">
      <h1 className="text-2xl font-semibold">Security</h1>
      <p className="mt-2 text-sm text-gray-600">
        Add a second factor to protect your account. We recommend enrolling at least two authenticators
        on different devices.
      </p>

      <section className="mt-8">
        <h2 className="text-lg font-medium">Authenticator apps</h2>

        {isLoading && <p className="text-sm">Loading…</p>}

        {!isLoading && verified.length === 0 && !showEnroll && (
          <div className="mt-4 rounded border border-yellow-300 bg-yellow-50 p-4">
            <p className="text-sm">You don't have MFA enabled yet.</p>
          </div>
        )}

        {verified.length > 0 && (
          <ul className="mt-4 divide-y rounded border">
            {verified.map((f) => (
              <li key={f.id} className="flex items-center justify-between p-4">
                <div>
                  <p className="font-medium">{f.friendly_name ?? "Authenticator"}</p>
                  <p className="text-xs text-gray-500">
                    Added {new Date(f.created_at).toLocaleDateString()}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => removeFactor(f.id)}
                  disabled={removingId === f.id}
                  className="text-sm text-red-700 hover:underline disabled:opacity-50"
                >
                  {removingId === f.id ? "Removing…" : "Remove"}
                </button>
              </li>
            ))}
          </ul>
        )}

        {removeError && <p className="mt-2 text-sm text-red-700">{removeError}</p>}

        {pending.length > 0 && (
          <div className="mt-4 rounded border border-gray-300 bg-gray-50 p-3 text-sm">
            <p className="font-medium">Pending enrollments</p>
            <ul className="mt-2 space-y-1">
              {pending.map((f) => (
                <li key={f.id} className="flex justify-between">
                  <span>{f.friendly_name ?? "Unverified factor"}</span>
                  <button
                    type="button"
                    onClick={() => removeFactor(f.id)}
                    className="text-xs text-red-700 hover:underline"
                  >
                    Discard
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        {!showEnroll && (
          <button
            type="button"
            onClick={() => setShowEnroll(true)}
            className="mt-4 rounded bg-blue-600 px-4 py-2 text-white"
          >
            {verified.length === 0 ? "Set up authenticator" : "Add another authenticator"}
          </button>
        )}

        {showEnroll && (
          <div className="mt-6 rounded border p-6">
            <EnrollTotpFlow
              onComplete={() => {
                setShowEnroll(false);
                refetch();
              }}
              onCancel={() => setShowEnroll(false)}
            />
          </div>
        )}
      </section>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add app/routes/account.security.tsx
git commit -m "feat(mfa): account security page — list, add, remove factors"
```

---

## Task 7: Login flow update — prompt for MFA after sign-in

**Files:**
- Modify: the post-login callback / auth-state handler (likely `app/routes/auth.callback.tsx`, `app/lib/auth/session.ts`, or a `useAuth` hook)

> Supabase's JS SDK promotes sessions automatically when MFA is enrolled — after sign-in via magic link or password, `getAuthenticatorAssuranceLevel()` returns `{currentLevel: 'aal1', nextLevel: 'aal2'}` indicating "you can/should upgrade to aal2". We prompt the user immediately so subsequent navigation to AAL2-gated routes doesn't hit the interceptor.

- [ ] **Step 1: After sign-in success, check AAL**

```typescript
// In the auth callback or session-loaded hook:
import { getAal } from "~/lib/supabase/mfa";
import { openStepUpAndAwait } from "~/components/mfa/StepUpProvider";

async function maybePromptForMfa() {
  const aal = await getAal();
  if (aal.nextLevel === "aal2" && aal.currentLevel !== "aal2") {
    try {
      await openStepUpAndAwait({
        reason: "mfa_required",
        message: "Verify with your authenticator to complete sign-in.",
      });
    } catch {
      // User dismissed — they remain at aal1. They'll still hit the
      // step-up modal next time they touch a gated route. That's fine.
    }
  }
}
```

Call `maybePromptForMfa()` after the session is established. The exact wiring depends on the existing auth flow — most likely a single `useEffect` in a top-level layout that watches `supabase.auth.onAuthStateChange`.

- [ ] **Step 2: Commit**

```bash
git commit -am "feat(mfa): prompt for MFA verification immediately after sign-in"
```

---

## Task 8: Staff first-login enrollment gate

**Files:**
- Create: `app/routes/account.security.enroll-required.tsx`
- Modify: staff route guard / layout

> Staff users who have not yet enrolled MFA get redirected here on first staff-area access. The page is intentionally minimal — enroll, or sign out.

- [ ] **Step 1: Write the gate page**

```typescript
// app/routes/account.security.enroll-required.tsx
import { useNavigate } from "@remix-run/react";
import { EnrollTotpFlow } from "~/components/mfa/EnrollTotpFlow";
import { supabase } from "~/lib/supabase/client";

export default function EnrollRequiredPage() {
  const navigate = useNavigate();

  return (
    <div className="mx-auto max-w-md py-16">
      <h1 className="text-2xl font-semibold">MFA required</h1>
      <p className="mt-2 text-sm text-gray-600">
        Staff accounts must have an authenticator factor enrolled. This is a one-time setup —
        it takes about a minute.
      </p>

      <div className="mt-8 rounded border p-6">
        <EnrollTotpFlow onComplete={() => navigate("/staff")} />
      </div>

      <button
        type="button"
        onClick={() => supabase.auth.signOut()}
        className="mt-6 text-sm text-gray-500 hover:underline"
      >
        Sign out
      </button>
    </div>
  );
}
```

- [ ] **Step 2: Add the guard to the staff layout**

In whatever component is the staff-area layout/wrapper (e.g. `app/routes/staff.tsx`):

```typescript
import { useEffect } from "react";
import { useNavigate } from "@remix-run/react";
import { useHasMfa } from "~/hooks/useMfa";

export default function StaffLayout() {
  const { data: hasMfa, isLoading } = useHasMfa();
  const navigate = useNavigate();

  useEffect(() => {
    if (!isLoading && hasMfa === false) {
      navigate("/account/security/enroll-required", { replace: true });
    }
  }, [hasMfa, isLoading, navigate]);

  if (isLoading || hasMfa === false) return null;

  return <Outlet />;
}
```

This is a *defense in depth* check — even if the user dodges this guard, the backend's `require.aal2` middleware on every staff route still returns 401 and forces the step-up modal. The guard exists to give a friendlier UX than "every staff request fails until you find Security settings."

- [ ] **Step 3: Commit**

```bash
git add app/routes/account.security.enroll-required.tsx app/routes/staff.tsx
git commit -m "feat(mfa): staff enrollment gate — redirect to enroll-required page when no factors"
```

---

## Task 9: Tests

**Files:**
- Create: `app/lib/api/__tests__/interceptor.test.ts`
- Create: `app/components/mfa/__tests__/StepUpModal.test.tsx`
- Create: `app/components/mfa/__tests__/EnrollTotpFlow.test.tsx`

The test setup depends on which test runner the repo uses (Vitest / Jest). Adapt the syntax — the assertions are the load-bearing part.

- [ ] **Step 1: Interceptor test**

```typescript
// Asserts:
// 1. 401 + code:mfa_required triggers openStepUpAndAwait
// 2. After step-up resolves, the original request is retried exactly once
// 3. If step-up rejects (user cancels), MfaRequiredError is thrown
// 4. Non-MFA 401s pass through unchanged
// 5. Successful responses (2xx) skip the interceptor entirely
```

- [ ] **Step 2: StepUpModal test**

```typescript
// Asserts:
// 1. With zero verified factors, shows "no factors" warning
// 2. With one factor, auto-selects it
// 3. With multiple factors, shows a select
// 4. Enter key submits when code is 6 digits
// 5. Verify error displays the message verbatim (covers brute-force surface)
// 6. Cancel button calls onCancel
```

- [ ] **Step 3: EnrollTotpFlow test**

```typescript
// Asserts:
// 1. Friendly name input is required (button disabled if empty)
// 2. On successful enroll, QR code renders
// 3. On successful verify, onComplete is called
// 4. Verify failure clears the code input but stays in verify step
```

- [ ] **Step 4: Run the test suite**

```bash
npm test
```

Expected: all new tests pass; no regressions in existing tests.

- [ ] **Step 5: Commit**

```bash
git commit -am "test(mfa): interceptor, step-up modal, enrollment flow"
```

---

## Task 10: End-to-end smoke test against dev backend

> Pre-req: backend PR has merged and the operator has (a) enabled TOTP in the dev Supabase project, (b) configured the MFA Verification Hook URL in the Supabase dashboard, (c) confirmed `core.auth_factor_events` is reachable.

- [ ] **Step 1: Enroll a TOTP factor**

In the running dev frontend:
1. Sign in with a test account.
2. Navigate to `/account/security`.
3. Click "Set up authenticator". Enter a friendly name.
4. Scan the QR code with Google Authenticator (or 1Password / Bitwarden).
5. Enter the 6-digit code. Verify success.

- [ ] **Step 2: Check the audit log (operator step)**

The operator queries `core.auth_factor_events` in the Supabase SQL editor:

```sql
SELECT event_type, factor_type, created_at FROM core.auth_factor_events
WHERE user_id = '<your-test-uid>'
ORDER BY created_at DESC LIMIT 10;
```

Expected: at least one `verify_success` row with `factor_type = 'totp'`.

- [ ] **Step 3: Test the step-up flow**

1. Sign out and sign back in.
2. Note that the step-up modal appears immediately (Task 7 behavior).
3. Verify the code. Confirm `getAal()` now returns `currentLevel: 'aal2'`.

- [ ] **Step 4: Test the fresh-MFA unenroll gate**

1. Wait 90 seconds after the most recent verify.
2. Navigate to `/account/security` and click "Remove" on the factor.
3. Expected: step-up modal opens (because the 60s window has expired).
4. Verify the code. Confirm the factor is removed.

- [ ] **Step 5: Test the brute-force defense**

1. Re-enroll a factor.
2. Open the step-up modal (sign out + sign in).
3. Enter 5 wrong codes in a row. Each should fail with "Code did not verify".
4. On the 6th wrong code, expect the error message "Too many failed verification attempts. Try again in 5 minutes."
5. Operator confirms `core.auth_factor_events` has 5× `verify_failed` and 1× `verify_rejected_by_hook` for the test user.

- [ ] **Step 6: Test the staff enrollment gate**

(Requires a test user with the staff role.)

1. Remove all MFA factors from the test staff user (via Supabase Dashboard).
2. Sign in as that user.
3. Navigate to a staff page.
4. Expected: redirected to `/account/security/enroll-required`.
5. Enroll a factor. Confirm redirect to `/staff` and access to staff routes works.

- [ ] **Step 7: Open the PR**

```bash
git push -u origin feat/mfa-frontend
```

Open a PR with this body:

```markdown
## Summary
- Adds TOTP enrollment / management UI under `/account/security`
- Global step-up modal triggered automatically by the API client when the backend returns 401 with `code: mfa_required` or `code: mfa_fresh_required`
- Staff first-login enrollment gate at `/account/security/enroll-required`
- Login flow prompts for MFA immediately when the session is at aal1 and a verified factor exists

## Sister plan
Backend: see `partna-backend` PR <link> — backend foundation must merge first.

## Test plan
- [x] Smoke-tested against dev backend (Tasks 10.1–10.6 above)
- [ ] After merge: re-run smoke tests against prod once the backend rolls out
```

---

## What's deliberately NOT in this plan

- **Phone/SMS factor UI.** Not enabling SMS at launch.
- **WebAuthn / passkey UI.** Deferred until Supabase marks it GA.
- **Recovery code UI.** We're not generating recovery codes — factor diversity is the recovery story.
- **Per-tenant MFA configuration.** No "this brand requires MFA for all its affiliates" yet.
- **Risk-based / adaptive challenges.** Deferred until there's measurable attack pressure.

## Coordination notes for Josh

- **Backend PR must merge first.** The frontend PR depends on the `/api/account/mfa/factors/{id}` endpoint and the `mfa_required` / `mfa_fresh_required` response codes.
- **Supabase TOTP must be enabled in the dev project before frontend smoke tests** (see backend plan's pre-flight section).
- **The MFA Verification Hook URL in the Supabase dashboard points at the backend** — frontend doesn't need to know about this hook directly; the only frontend-visible effect is the rejection-message string surfacing through `verify()` errors.
- **No feature flag was added to either plan per your instruction** — staff lockout on backend merge is real and immediate. Plan staff enrollment communication accordingly (e.g. enroll staff users yourself before merging, or merge during a window when staff impact is acceptable).
