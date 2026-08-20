"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";

import { ApiError, Unauthenticated, api } from "@/lib/api";

export interface ActionState {
  error?: string;
  ok?: boolean;
}

/**
 * Runs the agent against a claim. The call is synchronous by design: the
 * whole verification is one bounded round trip, so the console can wait for
 * it rather than poll a job.
 */
export async function runVerification(
  _previous: ActionState,
  formData: FormData,
): Promise<ActionState> {
  // The claim id travels as a hidden field rather than through .bind().
  //
  // Binding an argument makes React namespace every named input in the form
  // ("_1_notes" instead of "notes") so it can reconstruct the argument list,
  // and a plain formData.get("notes") on the server then reads nothing. The
  // failure is silent for optional fields, which is how it survived here.
  const claimId = String(formData.get("claim_id") ?? "");
  const scenario = String(formData.get("scenario") ?? "");
  const forceMode = String(formData.get("force_mode") ?? "");

  if (!claimId) return { error: "No claim was identified for this verification." };

  const body: Record<string, string> = {};
  if (scenario) body.scenario = scenario;
  if (forceMode) body.force_mode = forceMode;

  try {
    await api(`/claims/${claimId}/verify`, { method: "POST", body });
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError) return { error: error.message };
    return { error: "The verification could not be started. Check that the agent runtime is up." };
  }

  revalidatePath(`/claims/${claimId}`);
  revalidatePath("/claims");
  revalidatePath("/overview");
  revalidatePath("/reviews");

  return { ok: true };
}

/**
 * A reviewer's decision never overwrites the agent's. Laravel writes a new
 * decision with origin HUMAN and marks the previous one superseded.
 */
export async function resolveReview(
  _previous: ActionState,
  formData: FormData,
): Promise<ActionState> {
  // See runVerification: the id is a hidden field, not a bound argument.
  const reviewId = String(formData.get("review_id") ?? "");

  // The API's vocabulary, not the interface's. A reviewer reads "uphold";
  // the record says CONFIRMED, and the note field is `notes`.
  const outcome = String(formData.get("outcome") ?? "");
  const overrideState = String(formData.get("override_state") ?? "");
  const notes = String(formData.get("notes") ?? "").trim();

  if (!reviewId) return { error: "No review was identified." };

  if (outcome !== "CONFIRMED" && outcome !== "OVERRIDDEN") {
    return { error: "Choose whether you are upholding or overriding the decision." };
  }
  if (outcome === "OVERRIDDEN" && !overrideState) {
    return { error: "Choose the state you are overriding to." };
  }
  if (notes.length < 3) {
    return { error: "Add a short note. The next person reading this needs to know why." };
  }

  try {
    await api(`/reviews/${reviewId}/resolve`, {
      method: "POST",
      body: {
        outcome,
        notes,
        ...(outcome === "OVERRIDDEN" ? { override_state: overrideState } : {}),
      },
    });
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError) return { error: error.message };
    return { error: "The review could not be resolved." };
  }

  revalidatePath("/reviews");
  revalidatePath("/overview");

  return { ok: true };
}
