"use client";

import { useActionState, useState } from "react";
import { useFormStatus } from "react-dom";

import { resolveReview, type ActionState } from "@/actions/verification";

function Submit({ overriding }: { overriding: boolean }) {
  const { pending } = useFormStatus();

  return (
    <button type="submit" className="btn btn-primary" disabled={pending}>
      {pending ? "Recording…" : overriding ? "Override the decision" : "Uphold the decision"}
    </button>
  );
}

/**
 * Resolving a review never edits the agent's decision. It writes a new one
 * with origin HUMAN and marks the original superseded — so the record of
 * what the system believed, and when, survives the disagreement.
 */
export function ReviewForm({ reviewId }: { reviewId: string }) {
  const [state, formAction] = useActionState<ActionState, FormData>(resolveReview, {});
  // CONFIRMED is what the API calls upholding a decision.
  const [outcome, setOutcome] = useState("CONFIRMED");

  const overriding = outcome === "OVERRIDDEN";

  return (
    <form action={formAction} className="grid gap-4 p-4">
      <input type="hidden" name="review_id" value={reviewId} />

      <fieldset className="grid gap-2">
        <legend className="u-eyebrow mb-1">Your call</legend>

        {[
          ["CONFIRMED", "Uphold", "The evidence supports what the system concluded."],
          ["OVERRIDDEN", "Override", "You have information the network could not see."],
        ].map(([value, label, hint]) => (
          <label
            key={value}
            className="flex cursor-pointer gap-2.5 border border-rule p-2.5"
            style={{
              borderRadius: 2,
              borderColor: outcome === value ? "var(--color-signal)" : undefined,
              background: outcome === value ? "var(--color-signal-wash)" : undefined,
            }}
          >
            <input
              type="radio"
              name="outcome"
              value={value}
              checked={outcome === value}
              onChange={() => setOutcome(value)}
              className="mt-1"
            />
            <span>
              <span className="block text-[13px] font-medium">{label}</span>
              <span className="block text-[12px] text-ink-2">{hint}</span>
            </span>
          </label>
        ))}
      </fieldset>

      {overriding && (
        <label className="grid gap-1.5">
          <span className="u-eyebrow">Override to</span>
          <select name="override_state" className="field" defaultValue="VERIFIED">
            <option value="VERIFIED">Verified</option>
            <option value="PARTIAL">Partial</option>
            <option value="DISPUTED">Disputed</option>
            <option value="UNVERIFIED">Unverified</option>
          </select>
        </label>
      )}

      <label className="grid gap-1.5">
        <span className="u-eyebrow">Why</span>
        <textarea
          name="notes"
          className="field"
          rows={3}
          placeholder="What did you check, and what did you find?"
          required
        />
        <span className="text-[11px] text-ink-3">
          This note travels with the decision permanently. Write it for the person who reads it in
          six months.
        </span>
      </label>

      {state.error && (
        <p className="u-machine border-l-2 border-disputed pl-2 text-disputed">{state.error}</p>
      )}
      {state.ok && (
        <p className="u-machine border-l-2 border-verified pl-2 text-verified">
          Recorded. A new decision was written and the original marked superseded.
        </p>
      )}

      <Submit overriding={overriding} />
    </form>
  );
}
