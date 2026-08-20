"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";

import { signIn, type LoginState } from "@/actions/auth";
import { DEMO_ACCOUNTS } from "@/lib/demo-accounts";

/**
 * The one-click accounts.
 *
 * Each is a plain submit button carrying `name="demo"`, so the choice travels
 * in the same FormData as the manual fields and the server action resolves it
 * against its allowlist. No client state and no fetch — which also means it
 * still works before hydration, and cannot get stuck in a spinner if the JS
 * bundle is slow on conference wifi.
 *
 * `formNoValidate` is what lets these bypass the required manual fields.
 */
function DemoAccounts() {
  const { pending, data } = useFormStatus();
  const active = data?.get("demo");

  return (
    <div className="grid gap-1.5">
      {DEMO_ACCOUNTS.map((account) => {
        const signingIn = active === account.email;

        return (
          <button
            key={account.email}
            type="submit"
            name="demo"
            value={account.email}
            formNoValidate
            disabled={pending}
            className="account-row"
          >
            <span className="text-[13px] font-medium">{account.label}</span>
            <span className={`u-machine ${signingIn ? "pulse text-signal" : ""}`}>
              {signingIn ? "signing in…" : account.email}
            </span>
          </button>
        );
      })}
    </div>
  );
}

function Submit() {
  const { pending, data } = useFormStatus();

  // Only claim the spinner when it was this button that was pressed.
  const manual = pending && !data?.get("demo");

  return (
    <button type="submit" className="btn btn-primary mt-4 w-full" disabled={pending}>
      {manual ? "Signing in…" : "Sign in"}
    </button>
  );
}

export function SignInForm() {
  const [state, action] = useActionState<LoginState, FormData>(signIn, {});

  return (
    <form action={action}>
      <div className="grid gap-3">
        <label className="grid gap-1.5">
          <span className="u-eyebrow">Email</span>
          <input
            className="field"
            type="email"
            name="email"
            autoComplete="username"
            placeholder="you@company.com"
            required
          />
        </label>

        <label className="grid gap-1.5">
          <span className="u-eyebrow">Password</span>
          <input
            className="field"
            type="password"
            name="password"
            autoComplete="current-password"
            required
          />
        </label>

        {state.error && (
          <p className="u-machine border-l-2 border-disputed pl-2 text-disputed">{state.error}</p>
        )}
      </div>

      <Submit />

      <div className="mt-6 border-t border-rule-soft pt-4">
        <p className="u-eyebrow">Demo accounts · one click, password &ldquo;password&rdquo;</p>
        <div className="mt-2.5">
          <DemoAccounts />
        </div>
        <p className="mt-3 text-[11px] leading-snug text-ink-3">
          The field technician account belongs on the handset, not here — sign in as
          tech@acme-field.test in the Flutter app.
        </p>
      </div>
    </form>
  );
}
