"use client";

import { useActionState } from "react";
import { useFormStatus } from "react-dom";

import { runVerification, type ActionState } from "@/actions/verification";

function RunButton({ hasRun }: { hasRun: boolean }) {
  const { pending } = useFormStatus();

  return (
    <button type="submit" className="btn btn-primary w-full" disabled={pending}>
      {pending ? (
        <span className="pulse">Gathering network evidence…</span>
      ) : hasRun ? (
        "Verify again"
      ) : (
        "Verify with network evidence"
      )}
    </button>
  );
}

/**
 * The scenario selector is a demo affordance, and it is labelled as one.
 * Leaving it on "As configured" runs exactly what a production tenant runs.
 */
export function VerifyPanel({ claimId, hasRun }: { claimId: string; hasRun: boolean }) {
  const [state, formAction] = useActionState<ActionState, FormData>(runVerification, {});

  return (
    <form action={formAction} className="grid gap-3 p-4">
      <input type="hidden" name="claim_id" value={claimId} />

      <RunButton hasRun={hasRun} />

      <details className="mt-1">
        <summary className="u-eyebrow cursor-pointer select-none hover:text-ink">
          Demo controls
        </summary>

        <div className="mt-3 grid gap-3">
          <label className="grid gap-1.5">
            <span className="u-eyebrow">Evidence source</span>
            <select name="force_mode" className="field" defaultValue="">
              <option value="">As configured</option>
              <option value="live">Live network only</option>
              <option value="demo">Simulated only</option>
            </select>
          </label>

          <label className="grid gap-1.5">
            <span className="u-eyebrow">Pin outcome (simulated evidence)</span>
            <select name="scenario" className="field" defaultValue="">
              <option value="">Let the network decide</option>
              <option value="VERIFIED">Consistent site</option>
              <option value="DISPUTED">Conflicting site</option>
              <option value="UNVERIFIED">Network unavailable</option>
            </select>
          </label>

          <p className="text-[11px] leading-snug text-ink-3">
            Pinning an outcome only affects the simulated adapter. It cannot change how live
            evidence is read, and every simulated item stays tagged as simulated.
          </p>
        </div>
      </details>

      {state.error && (
        <p className="u-machine border-l-2 border-disputed pl-2 text-disputed">{state.error}</p>
      )}
    </form>
  );
}
