import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { ApiError, Unauthenticated, api } from "@/lib/api";
import { ms, stamp } from "@/lib/format";
import type { VerificationRun } from "@/lib/types";
import { AssuranceMeter } from "@/components/AssuranceMeter";
import { EvidenceCard } from "@/components/EvidenceCard";
import { EvidenceTape } from "@/components/EvidenceTape";
import { Empty, Field, Panel } from "@/components/Panel";

export const dynamic = "force-dynamic";

export default async function VerificationPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let run: VerificationRun;

  try {
    run = (await api<{ data: VerificationRun }>(`/verifications/${id}`)).data;
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError && error.status === 404) notFound();
    throw error;
  }

  const decision = run.decision ?? null;

  return (
    <div className="grid gap-6">
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="u-eyebrow">Verification run</p>
          <h1 className="u-ref mt-1.5 text-[22px] leading-none">{run.policy?.name ?? run.id}</h1>
          <p className="u-machine mt-1.5">{run.id}</p>
        </div>
        <p className="u-machine">
          {stamp(run.started_at)} → {stamp(run.completed_at)} · {ms(run.duration_ms)}
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-[1.35fr_1fr]">
        <div className="grid content-start gap-6">
          {decision && (
            <section className="panel verdict p-5" data-state={decision.state}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  <p className="u-eyebrow">Decision</p>
                  <h2 className="u-figure mt-1 text-[32px]" style={{ color: "var(--state)" }}>
                    {decision.state}
                  </h2>
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
            </section>
          )}

          <section className="grid gap-3">
            {(run.evidence ?? []).length === 0 ? (
              <Panel>
                <Empty headline="No evidence was recorded on this run." />
              </Panel>
            ) : (
              (run.evidence ?? []).map((item) => <EvidenceCard key={item.id} evidence={item} />)
            )}
          </section>
        </div>

        <div className="grid content-start gap-6">
          <Panel eyebrow="What the agent did" title="Evidence tape">
            <EvidenceTape trace={run.trace ?? []} />
          </Panel>

          <Panel eyebrow="Run detail" title="Budget and runtime">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
              <Field
                label="API calls"
                value={`${run.budget.tool_calls_used} of ${run.budget.max_tool_calls}`}
                mono
              />
              <Field label="Latency budget" value={ms(run.budget.max_latency_ms)} mono />
              <Field label="Planner" value={run.agent.planner_mode ?? "—"} mono />
              <Field label="Model" value={run.agent.model ?? "none"} mono />
              <Field label="Agent version" value={run.agent.version ?? "—"} mono />
              <Field label="Status" value={run.status} mono />
            </dl>
          </Panel>

          <Link href="/claims" className="btn btn-quiet">
            Back to claims
          </Link>
        </div>
      </div>
    </div>
  );
}
