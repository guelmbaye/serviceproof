import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { ApiError, Unauthenticated, api, tryApi } from "@/lib/api";
import { reviewReason, stamp } from "@/lib/format";
import type { Evidence, Review, SessionUser, VerificationRun } from "@/lib/types";
import { AssuranceMeter } from "@/components/AssuranceMeter";
import { EvidenceCard } from "@/components/EvidenceCard";
import { EvidenceTape } from "@/components/EvidenceTape";
import { Empty, Field, Panel } from "@/components/Panel";
import { ReviewForm } from "@/components/ReviewForm";

export const dynamic = "force-dynamic";

export default async function ReviewPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let review: Review;
  let me: SessionUser | null = null;

  try {
    const [reviewResponse, meResponse] = await Promise.all([
      api<{ data: Review }>(`/reviews/${id}`),
      tryApi<{ data: SessionUser }>("/auth/me"),
    ]);
    review = reviewResponse.data;
    me = meResponse?.data ?? null;
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError && error.status === 404) notFound();
    throw error;
  }

  const claim = review.claim ?? null;
  const decision = review.decision ?? null;

  let run: VerificationRun | null = claim?.verification ?? null;
  if (run?.id) {
    const detail = await tryApi<{ data: VerificationRun }>(`/verifications/${run.id}`);
    if (detail?.data) run = detail.data;
  }

  const evidence: Evidence[] = run?.evidence ?? [];
  const open = review.status === "OPEN" || review.status === "IN_PROGRESS";
  const canResolve = (me?.capabilities.can_review ?? false) && open;

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">
          <Link href="/reviews" className="hover:text-ink">
            Review queue
          </Link>
        </p>
        <h1 className="u-ref mt-1.5 text-[24px] leading-none">
          {claim?.work_order?.reference ?? claim?.reference ?? "Review"}
        </h1>
        <p className="mt-1.5 text-[13px] text-ink-2">
          {claim?.work_order?.customer ?? "—"} · {claim?.work_order?.site ?? "—"}
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-[1.35fr_1fr]">
        <div className="grid content-start gap-6">
          {decision && (
            <section className="panel verdict p-5" data-state={decision.state}>
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                  {/* After an override this panel shows the reviewer's own
                      decision, so calling it the system's conclusion would be
                      false — and false in the direction that matters, since
                      the point of the override is that a person disagreed. */}
                  <p className="u-eyebrow">
                    {decision.origin === "HUMAN"
                      ? "What a reviewer decided"
                      : "What the system concluded"}
                  </p>
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

          {review.reason && (
            <Panel eyebrow="Why this reached you" title="Escalation reason">
              <p className="p-4 text-[13px] leading-relaxed text-ink-2">
                {reviewReason(review.reason)}
              </p>
            </Panel>
          )}

          <section className="grid gap-3">
            <h2 className="text-[15px]">The evidence you are weighing</h2>
            {evidence.length === 0 ? (
              <Panel>
                <Empty headline="No evidence is attached to this review." />
              </Panel>
            ) : (
              evidence.map((item) => <EvidenceCard key={item.id} evidence={item} />)
            )}
          </section>
        </div>

        <div className="grid content-start gap-6">
          {canResolve ? (
            <Panel eyebrow="Resolve" title="Your decision">
              <ReviewForm reviewId={review.id} />
            </Panel>
          ) : (
            <Panel eyebrow={review.status.toLowerCase()} title="Resolution">
              <dl className="grid gap-3 p-4">
                <Field
                  label="Outcome"
                  value={
                    review.outcome === "CONFIRMED"
                      ? "Upheld"
                      : review.outcome === "OVERRIDDEN"
                        ? "Overridden"
                        : "—"
                  }
                />
                <Field label="Override" value={review.override_state ?? "none"} mono />
                <Field label="Resolved by" value={review.resolved_by ?? "—"} />
                <Field label="Resolved at" value={stamp(review.resolved_at)} mono />
                {review.resolution_notes && (
                  <Field label="Note" value={review.resolution_notes} />
                )}
              </dl>
            </Panel>
          )}

          {run && (
            <Panel eyebrow="What the agent did" title="Evidence tape">
              <EvidenceTape trace={run.trace ?? []} />
            </Panel>
          )}

          {claim && (
            <Link href={`/claims/${claim.id}`} className="btn btn-quiet">
              Open the full claim
            </Link>
          )}
        </div>
      </div>
    </div>
  );
}
