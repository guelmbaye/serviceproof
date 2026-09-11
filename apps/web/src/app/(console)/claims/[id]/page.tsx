import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { ApiError, Unauthenticated, api, tryApi } from "@/lib/api";
import { humanise, ms, stamp } from "@/lib/format";
import type { Claim, Evidence, SessionUser, VerificationRun } from "@/lib/types";
import { AssuranceMeter } from "@/components/AssuranceMeter";
import { EvidenceBudget } from "@/components/EvidenceBudget";
import { EvidenceCard } from "@/components/EvidenceCard";
import { EvidenceTape } from "@/components/EvidenceTape";
import { Empty, Field, Panel } from "@/components/Panel";
import { VerifyPanel } from "@/components/VerifyPanel";

export const dynamic = "force-dynamic";

export default async function ClaimPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let claim: Claim;
  let me: SessionUser | null = null;

  try {
    const [claimResponse, meResponse] = await Promise.all([
      api<{ data: Claim }>(`/claims/${id}`),
      tryApi<{ data: SessionUser }>("/auth/me"),
    ]);
    claim = claimResponse.data;
    me = meResponse?.data ?? null;
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError && error.status === 404) notFound();
    throw error;
  }

  // The claim endpoint returns the latest run; the run endpoint returns its
  // evidence and trace. Two calls, because a list of claims should not carry
  // every trace event ever recorded.
  let run: VerificationRun | null = claim.verification ?? null;

  if (run?.id) {
    const detail = await tryApi<{ data: VerificationRun }>(`/verifications/${run.id}`);
    if (detail?.data) run = detail.data;
  }

  const decision = claim.decision ?? run?.decision ?? null;
  const evidence: Evidence[] = run?.evidence ?? [];
  const canVerify = me?.capabilities.can_trigger_verification ?? false;

  return (
    <div className="grid gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="u-eyebrow">
            <Link href="/claims" className="hover:text-ink">
              Claims
            </Link>{" "}
            / {claim.work_order?.reference ?? "—"}
          </p>
          <h1 className="u-ref mt-1.5 text-[26px] leading-none">{claim.reference}</h1>
          <p className="mt-1.5 text-[13px] text-ink-2">
            {claim.work_order?.customer ?? "—"} · {claim.work_order?.site ?? "—"}
          </p>
        </div>

        <dl className="grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-3">
          <Field label="Claimed" value={stamp(claim.claimed_at)} mono />
          <Field label="Worker" value={claim.worker?.reference ?? "—"} mono />
          <Field label="Device" value={claim.device?.reference ?? "unmapped"} mono />
        </dl>
      </header>

      <div className="grid gap-6 lg:grid-cols-[1.35fr_1fr]">
        {/* ── left: the verdict and what it rests on ─────────────────── */}
        <div className="grid content-start gap-6">
          {decision ? (
            <section className="panel verdict" data-state={decision.state}>
              <div className="p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="u-eyebrow">Decision</p>
                    <h2
                      className="u-figure mt-1 text-[34px]"
                      style={{ color: "var(--state)" }}
                    >
                      {decision.state}
                    </h2>
                    <p className="mt-1 text-[13px] text-ink-2">
                      {decision.recommended_action_label}
                    </p>
                  </div>

                  <div className="w-full min-w-0 flex-1 sm:min-w-[220px]">
                    <AssuranceMeter
                      score={decision.assurance.score}
                      breakdown={decision.assurance.breakdown}
                      state={decision.state}
                    />
                  </div>
                </div>

                {decision.rationale && (
                  <p className="mt-5 border-t border-rule-soft pt-4 text-[14px] leading-relaxed">
                    {decision.rationale}
                  </p>
                )}

                  {/* Spec §43. Four statements, on the screen where a decision is read
                      rather than in a help centre nobody opens. Each answers an
                      objection a reviewer would otherwise have to raise for us. */}
                  <details className="mt-4 border-t border-rule-soft pt-3">
                    <summary className="u-eyebrow cursor-pointer select-none hover:text-ink">
                      What this evidence does and does not establish
                    </summary>
                    <ul className="mt-2 grid gap-1.5 text-[12px] leading-relaxed text-ink-2">
                      <li>
                        Network evidence supports operational assurance. It does not
                        conclusively prove that a physical task was executed.
                      </li>
                      <li>
                        Location precision depends on operator and network conditions. A
                        verification radius is the area we asked about, not the accuracy the
                        network can resolve.
                      </li>
                      <li>
                        Device evidence establishes where a device was. It does not establish
                        which human was carrying it.
                      </li>
                      <li>
                        Unavailable evidence is never treated as negative evidence. A failed
                        network call is an absence, not an accusation.
                      </li>
                    </ul>
                  </details>

                <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-2">
                  <Field
                    label="Decided by"
                    value={decision.origin === "HUMAN" ? "A reviewer" : "The agent, then the guard"}
                  />
                  <Field label="Policy satisfied" value={decision.policy_satisfied ? "Yes" : "No"} />
                  <Field label="Recorded" value={stamp(decision.created_at)} mono />
                </dl>

                {decision.guard.applied && (
                  <div className="mt-4 border-l-2 border-signal bg-signal-wash px-3 py-2.5">
                    <p className="u-eyebrow text-signal">Guard applied</p>
                    <p className="mt-1 text-[12px] leading-relaxed text-ink-2">
                      {decision.guard.reason ??
                        "The final state was re-derived from the persisted evidence."}
                    </p>
                  </div>
                )}

                {!decision.is_current && (
                  <p className="u-eyebrow mt-4 text-ink-3">
                    Superseded · a later decision replaced this one
                  </p>
                )}
              </div>
            </section>
          ) : (
            <Panel eyebrow="Not yet verified" title="No network evidence has been gathered">
              <Empty
                headline="This claim has not been checked against the network."
                hint={canVerify ? "Run a verification from the panel on the right." : undefined}
              />
            </Panel>
          )}

          <section>
            <div className="mb-3 flex items-baseline justify-between">
              <h2 className="text-[15px]">
                Evidence
                <span className="u-eyebrow ml-2">{evidence.length} item(s)</span>
              </h2>
              {run?.policy?.required_evidence && (
                <p className="u-machine">
                  policy requires {run.policy.required_evidence.map(humanise).join(", ")}
                </p>
              )}
            </div>

            {evidence.length === 0 ? (
              <Panel>
                <Empty headline="No evidence recorded for this claim." />
              </Panel>
            ) : (
              <div className="grid gap-3">
                {evidence.map((item) => (
                  <EvidenceCard key={item.id} evidence={item} />
                ))}
              </div>
            )}
          </section>

          {claim.notes && (
            <Panel eyebrow="Submitted with the claim" title="Worker notes">
              <div className="p-4">
                <p className="text-[13px] leading-relaxed text-ink-2">{claim.notes}</p>
                <p className="u-eyebrow mt-3 border-t border-rule-soft pt-3">
                  Treated as untrusted data. This text describes the job; it cannot instruct the
                  agent or change the policy.
                </p>
              </div>
            </Panel>
          )}
        </div>

        {/* ── right: run it, and watch what it did ───────────────────── */}
        <div className="grid content-start gap-6">
          {canVerify && (
            <Panel eyebrow="Network check" title="Verify this claim">
              <VerifyPanel claimId={claim.id} hasRun={Boolean(run)} />
            </Panel>
          )}

          {run && (
            <>
              <Panel eyebrow="Run detail" title="How this decision was reached">
                {/* The evidence budget, given the room it deserves.
                    "Why do you need an AI agent?" is answered here and nowhere
                    else: the agent chose to spend one call on a clean claim and
                    three on a contested one. As one field among six that point
                    was invisible, and it is the whole argument. */}
                <EvidenceBudget
                  used={run.budget.tool_calls_used}
                  max={run.budget.max_tool_calls}
                  escalated={run.escalated}
                  resolved={claim.decision?.policy_satisfied ?? false}
                />

                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-rule-soft p-4">
                  <Field label="Duration" value={ms(run.duration_ms)} mono />
                  <Field label="Policy" value={run.policy?.key ?? "—"} mono />
                  <Field label="Assurance level" value={run.assurance_level} mono />
                  <Field
                    label="Planner"
                    value={
                      run.agent.planner_mode === "heuristic"
                        ? "deterministic"
                        : `${run.agent.provider ?? "model"} · ${run.agent.planner_mode ?? "—"}`
                    }
                    mono
                  />
                </dl>

                {run.evidence_plan?.rationale && (
                  <div className="border-t border-rule-soft p-4">
                    <p className="u-eyebrow">Plan</p>
                    <p className="mt-1 text-[12px] leading-relaxed text-ink-2">
                      {run.evidence_plan.rationale}
                    </p>
                  </div>
                )}

                {run.agent.planner_mode === "llm_fallback_heuristic" && (
                  <p className="border-t border-rule-soft px-4 py-3 text-[12px] leading-relaxed text-ink-2">
                    The model was consulted but its answer was rejected or unavailable, so the
                    deterministic planner took over. The decision is unaffected.
                  </p>
                )}

                {run.used_demo_fallback && (
                  <p className="border-t border-rule-soft px-4 py-3 text-[12px] leading-relaxed text-partial">
                    This run used simulated evidence. It is tagged as such on every item and in the
                    assurance score.
                  </p>
                )}

                {run.failure_reason && (
                  <p className="u-machine border-t border-rule-soft px-4 py-3 text-disputed">
                    {run.failure_reason}
                  </p>
                )}
              </Panel>

              <Panel eyebrow="What the agent did" title="Evidence tape">
                <EvidenceTape trace={run.trace ?? []} />
              </Panel>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
