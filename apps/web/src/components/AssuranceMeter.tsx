import type { AssuranceBreakdown, DecisionState } from "@/lib/types";

const LABELS: Record<string, string> = {
  completeness: "Complete",
  consistency: "Consistent",
  freshness: "Fresh",
  availability: "Available",
};

const EXPLAIN: Record<string, string> = {
  completeness: "How much of what the policy requires was actually obtained.",
  consistency: "How much of the usable evidence agrees with the claim.",
  freshness: "How much of the evidence is inside the policy's freshness window.",
  availability: "How many of the attempted signals came back at all.",
};

/**
 * The score is a weighted sum of four measurable things, so it is drawn as
 * four segments: each segment's *width* is its weight in the formula, each
 * segment's *fill* is its measured value. Nothing here is a proxy for the
 * number — the number is the area.
 *
 * A reviewer who disagrees with a 41 can point at which segment is short.
 */
export function AssuranceMeter({
  score,
  breakdown,
  state,
}: {
  score: number | null;
  breakdown: AssuranceBreakdown | null;
  state: DecisionState;
}) {
  const components = breakdown?.components ?? {};
  const weights = breakdown?.weights ?? {};
  const keys = Object.keys(weights).length
    ? Object.keys(weights)
    : ["completeness", "consistency", "freshness", "availability"];

  const totalWeight = keys.reduce((sum, key) => sum + (weights[key] ?? 0.25), 0) || 1;
  const simulated = breakdown?.provenance === "INCLUDES_SIMULATED_EVIDENCE";

  return (
    <div data-state={state}>
      <div className="flex items-end justify-between gap-4">
        <div className="flex items-baseline gap-2">
          <span className="u-figure text-[40px]" style={{ color: "var(--state)" }}>
            {score ?? "—"}
          </span>
          <span className="u-eyebrow">/ 100 assurance</span>
        </div>
        {simulated && (
          <span className="u-machine text-[10px] tracking-[0.1em] uppercase text-partial">
            includes simulated evidence
          </span>
        )}
      </div>

      <div className="meter mt-3">
        {keys.map((key) => {
          const weight = weights[key] ?? 0.25;
          const value = components[key] ?? 0;

          return (
            <div
              key={key}
              className="meter-seg"
              style={{ flexGrow: weight / totalWeight, flexBasis: 0 }}
              title={`${LABELS[key] ?? key}: ${(value * 100).toFixed(0)}% — weighted ${(weight * 100).toFixed(0)}% of the score. ${EXPLAIN[key] ?? ""}`}
            >
              <div className="meter-fill" style={{ height: `${Math.round(value * 100)}%` }} />
            </div>
          );
        })}
      </div>

      <div className="mt-1.5 flex gap-2">
        {keys.map((key) => (
          <div
            key={key}
            className="min-w-0"
            style={{ flexGrow: (weights[key] ?? 0.25) / totalWeight, flexBasis: 0 }}
          >
            <p className="u-eyebrow truncate">{LABELS[key] ?? key}</p>
            <p className="u-machine">
              {((components[key] ?? 0) * 100).toFixed(0)}
              <span className="text-ink-3"> ×{((weights[key] ?? 0) * 100).toFixed(0)}%</span>
            </p>
          </div>
        ))}
      </div>
    </div>
  );
}
