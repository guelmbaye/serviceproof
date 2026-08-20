import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { ago, ms, percent, reviewReason } from "@/lib/format";
import type { AgentHealth, Overview } from "@/lib/types";
import { AgentStatus } from "@/components/AgentStatus";
import { Empty, Panel } from "@/components/Panel";
import { StateChip, StateLegend } from "@/components/StateChip";

export const dynamic = "force-dynamic";
export const metadata = { title: "Overview" };

export default async function OverviewPage() {
  let overview: Overview | null = null;
  let health: AgentHealth | null = null;

  try {
    [overview, health] = await Promise.all([
      tryApi<Overview>("/dashboard"),
      tryApi<AgentHealth>("/verifications/health"),
    ]);
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  if (!overview) {
    return (
      <Panel title="Overview unavailable">
        <Empty
          headline="The API did not return operational metrics."
          hint="Check that the Laravel container is running on port 8000."
        />
      </Panel>
    );
  }

  const { metrics } = overview;
  const states = [
    ["VERIFIED", metrics.decisions.verified],
    ["PARTIAL", metrics.decisions.partial],
    ["DISPUTED", metrics.decisions.disputed],
    ["UNVERIFIED", metrics.decisions.unverified],
  ] as const;
  const decided = metrics.claims.decided || 1;

  return (
    <div className="grid gap-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="u-eyebrow">Last 30 days</p>
          <h1 className="mt-1 text-[26px] leading-none">Operations</h1>
        </div>
        <p className="u-machine">
          {metrics.claims.total} claims · {metrics.claims.decided} decided ·{" "}
          {metrics.evidence.total} evidence items
        </p>
      </header>

      {/* ── decision mix: a stacked rule, not four cards ─────────────── */}
      <Panel eyebrow="Decision mix" title="What the evidence concluded">
        <div className="p-4">
          <div className="flex h-3 gap-0.5 overflow-hidden rounded-[2px]">
            {states.map(([state, count]) => (
              <div
                key={state}
                data-state={state}
                title={`${state}: ${count}`}
                style={{
                  flexGrow: Math.max(count, 0.02),
                  flexBasis: 0,
                  background: "var(--state)",
                }}
              />
            ))}
          </div>

          <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            {states.map(([state, count]) => (
              <div key={state} data-state={state}>
                <dt>
                  <StateChip state={state} />
                </dt>
                <dd className="u-figure mt-2 text-[28px]" style={{ color: "var(--state)" }}>
                  {count}
                </dd>
                <dd className="u-machine">{percent(count / decided)} of decided</dd>
              </div>
            ))}
          </dl>
        </div>
      </Panel>

      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="grid gap-6">
          <Panel eyebrow="How the agent is behaving" title="Automation">
            <dl className="grid grid-cols-2 gap-x-4 gap-y-5 p-4 sm:grid-cols-3">
              <Metric
                label="Auto-verified"
                value={percent(metrics.automation.auto_verification_rate, 1)}
                note="Closed without a reviewer."
              />
              <Metric
                label="Escalated"
                value={percent(metrics.automation.escalation_rate, 1)}
                note="Runs that needed a second signal."
              />
              <Metric
                label="API calls per run"
                value={metrics.automation.avg_tool_calls_per_run?.toFixed(2) ?? "—"}
                note="Lower is better. Evidence has a cost."
              />
              <Metric
                label="Time to decision"
                value={metrics.claims.decided > 0 ? ms(metrics.automation.avg_decision_ms) : "—"}
                note="Median across completed runs."
              />
              <Metric
                label="Open reviews"
                value={String(metrics.reviews.open)}
                note="Waiting on a human."
              />
              <Metric
                label="Simulated evidence"
                value={`${metrics.evidence.simulated} / ${metrics.evidence.total}`}
                note="Items that did not come from the live network."
              />
            </dl>
          </Panel>

          <Panel
            eyebrow="Latest runs"
            title="Verification history"
            aside={
              <Link href="/claims" className="u-eyebrow hover:text-ink">
                All claims →
              </Link>
            }
          >
            {overview.recent_verifications.length === 0 ? (
              <Empty
                headline="No verifications yet."
                hint="Open a claim and run one to see the evidence tape."
              />
            ) : (
              <ul>
                {overview.recent_verifications.map((run) => (
                  <li key={run.id}>
                    <Link href={`/verifications/${run.id}`} className="row-link">
                      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        {run.decision ? (
                          <StateChip state={run.decision.state} />
                        ) : (
                          <StateChip state="UNVERIFIED" title="No decision recorded" />
                        )}
                        <span className="u-ref text-[13px]">{run.policy?.key ?? "policy"}</span>
                        <span className="u-machine ml-auto">
                          {run.budget.tool_calls_used}/{run.budget.max_tool_calls} calls ·{" "}
                          {ms(run.duration_ms)}
                          {run.escalated && " · escalated"}
                          {run.used_demo_fallback && " · simulated"}
                        </span>
                      </div>
                      {run.decision?.rationale && (
                        <p className="mt-1 line-clamp-1 text-[12px] text-ink-2">
                          {run.decision.rationale}
                        </p>
                      )}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>

        <div className="grid content-start gap-6">
          <Panel eyebrow="Runtime" title="Agent status">
            <AgentStatus health={health} />
          </Panel>

          <Panel
            eyebrow={`${overview.exceptions.length} waiting`}
            title="Needs a human"
            aside={
              <Link href="/reviews" className="u-eyebrow hover:text-ink">
                Queue →
              </Link>
            }
          >
            {overview.exceptions.length === 0 ? (
              <Empty headline="Nothing is waiting on a reviewer." />
            ) : (
              <ul>
                {overview.exceptions.map((review) => (
                  <li key={review.id}>
                    <Link href={`/reviews/${review.id}`} className="row-link">
                      <div className="flex items-center gap-2">
                        {review.decision && <StateChip state={review.decision.state} />}
                        <span className="u-machine ml-auto">{ago(review.created_at)}</span>
                      </div>
                      <p className="mt-1.5 text-[13px] leading-snug">
                        {review.claim?.work_order?.reference ?? review.claim?.reference}
                        <span className="text-ink-2">
                          {" "}
                          · {review.claim?.work_order?.customer ?? "—"}
                        </span>
                      </p>
                      {review.reason && (
                        <p className="mt-0.5 line-clamp-2 text-[12px] text-ink-2">
                          {reviewReason(review.reason)}
                        </p>
                      )}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          <Panel eyebrow="Reading the states" title="What each verdict means">
            <div className="p-4">
              <StateLegend />
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}

function Metric({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <div>
      <dt className="u-eyebrow">{label}</dt>
      <dd className="u-figure mt-1.5 text-[24px]">{value}</dd>
      <dd className="mt-0.5 text-[11px] leading-snug text-ink-3">{note}</dd>
    </div>
  );
}
