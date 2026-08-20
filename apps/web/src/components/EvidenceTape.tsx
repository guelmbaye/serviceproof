import { clock, humanise } from "@/lib/format";
import type { TraceEvent } from "@/lib/types";

/**
 * The evidence tape.
 *
 * The agent's trace is a sequence of *actions*, not a stream of thought, so
 * it is drawn like an instrument tape rather than a chat log: one continuous
 * rule, a tick per event, a node where a tool was actually called, and a
 * notch where the agent broke off to escalate.
 *
 * Latency is drawn at true proportion. If a call took 2.4 seconds, the bar
 * is long. That is the honest thing to show an operations team, and it is
 * also the thing they will ask about first.
 */

/** Which events get a node, a notch, or a plain tick. */
function kindOf(eventType: string): "action" | "break" | "terminal" | "tick" {
  // VERIFICATION_REQUESTED opens the tape and falls through to a plain tick:
  // asking for a run is not itself an action against the network.
  if (eventType === "TOOL_CALLED") return "action";
  if (eventType === "ESCALATION_REQUIRED" || eventType === "EVIDENCE_CONFLICT") return "break";
  if (eventType === "AGENT_COMPLETED" || eventType === "DECISION_PROPOSED") return "terminal";
  return "tick";
}

/** Events whose detail carries a decision worth reading in full. */
const NARRATIVE = new Set([
  "PLAN_CREATED",
  "TOOL_SELECTED",
  "ESCALATION_REQUIRED",
  "POLICY_EVALUATED",
  "DECISION_PROPOSED",
  "BUDGET_EXHAUSTED",
]);

function detailString(detail: Record<string, unknown> | null, key: string): string | null {
  const value = detail?.[key];
  return typeof value === "string" && value.length > 0 ? value : null;
}

function detailNumber(detail: Record<string, unknown> | null, key: string): number | null {
  const value = detail?.[key];
  return typeof value === "number" ? value : null;
}

export function EvidenceTape({ trace }: { trace: TraceEvent[] }) {
  if (!trace.length) {
    return <p className="px-4 py-8 text-center text-[13px] text-ink-2">No trace recorded yet.</p>;
  }

  return (
    <ol className="tape px-4 py-4">
      {trace.map((event, index) => {
        const kind = kindOf(event.event_type);
        const reason =
          detailString(event.detail, "reason") ??
          detailString(event.detail, "rationale") ??
          detailString(event.detail, "summary");
        const decidedBy = detailString(event.detail, "decided_by");
        const requestId = detailString(event.detail, "request_id");
        const latency = detailNumber(event.detail, "latency_ms");
        const status = detailString(event.detail, "status");

        return (
          <li
            key={event.sequence}
            className="tape-event"
            data-kind={kind}
            data-state={status ?? undefined}
            style={{ animationDelay: `${Math.min(index * 40, 600)}ms` }}
          >
            <div className="flex items-baseline gap-2">
              <span className="u-eyebrow tabular-nums">
                {String(event.sequence).padStart(2, "0")}
              </span>
              <p className="flex-1 text-[13px] leading-snug">{event.label}</p>
              <span className="u-machine flex-none">{clock(event.occurred_at)}</span>
            </div>

            {reason && NARRATIVE.has(event.event_type) && (
              <p className="mt-1 text-[12px] leading-relaxed text-ink-2">{reason}</p>
            )}

            {decidedBy && (
              <p className="u-eyebrow mt-1">
                decided by {decidedBy === "llm" ? "the model" : "deterministic rules"}
              </p>
            )}

            {(requestId || latency !== null) && (
              <div className="mt-1.5 flex items-center gap-3">
                {requestId && <span className="u-machine flex-none">{requestId}</span>}
                {latency !== null && (
                  <>
                    <div
                      className="latency-bar"
                      style={{ width: `${Math.min(Math.round(latency / 20), 160)}px` }}
                    />
                    <span className="u-machine flex-none">{latency} ms</span>
                  </>
                )}
              </div>
            )}

            {event.event_type === "PLAN_CREATED" && Array.isArray(event.detail?.minimum) && (
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {(event.detail.minimum as string[]).map((type) => (
                  <span key={type} className="u-machine rounded-[2px] bg-signal-wash px-1.5 py-0.5">
                    min · {humanise(type)}
                  </span>
                ))}
                {(Array.isArray(event.detail?.escalation) ? (event.detail.escalation as string[]) : []).map(
                  (type) => (
                    <span key={type} className="u-machine rounded-[2px] border border-rule px-1.5 py-0.5">
                      esc · {humanise(type)}
                    </span>
                  ),
                )}
              </div>
            )}
          </li>
        );
      })}
    </ol>
  );
}
