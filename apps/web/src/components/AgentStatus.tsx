import type { AgentHealth } from "@/lib/types";

/**
 * The demo's honesty indicator. It states which planner is running and
 * whether CAMARA evidence will be live or simulated — before anyone asks.
 */
export function AgentStatus({ health }: { health: AgentHealth | null }) {
  if (!health) {
    return <p className="u-machine px-4 py-3">Agent status unavailable.</p>;
  }

  if (!health.agent?.reachable) {
    return (
      <div className="px-4 py-3">
        <p className="u-eyebrow text-disputed">Agent unreachable</p>
        <p className="u-machine mt-1">
          {health.agent?.detail?.error ?? "No response from the agent runtime."}
        </p>
        <p className="mt-2 text-[12px] text-ink-2">
          Verification is unavailable until the runtime is back. Existing decisions are unaffected.
        </p>
      </div>
    );
  }

  const detail = health.agent.detail;
  const planner = detail?.planner;
  const camara = detail?.camara;
  const live = camara?.live_credentials === true;

  return (
    <dl className="grid gap-2.5 px-4 py-3">
      <Line
        label="Planner"
        value={planner?.llm_enabled ? (planner.provider ?? "model") : "deterministic"}
        note={
          planner?.llm_enabled
            ? "A model chooses the next tool; every choice is validated before it runs."
            : "No external model. The same loop runs on explicit rules."
        }
      />
      <Line
        label="Network evidence"
        value={live ? `live · ${camara?.mode}` : `simulated · ${camara?.mode ?? "demo"}`}
        note={
          live
            ? "Calls go to Nokia Network as Code. The labelled fallback stays armed."
            : "No live credentials. Every evidence item will be tagged as simulated."
        }
        warn={!live}
      />
      <Line
        label="Runtime"
        value={`v${detail?.version ?? "?"}`}
        note={`${detail?.tools?.length ?? 0} tools available`}
      />
    </dl>
  );
}

function Line({
  label,
  value,
  note,
  warn = false,
}: {
  label: string;
  value: string;
  note: string;
  warn?: boolean;
}) {
  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <dt className="u-eyebrow">{label}</dt>
        <dd className={`u-machine ${warn ? "text-partial" : "text-ink"}`}>{value}</dd>
      </div>
      <p className="mt-0.5 text-[11px] leading-snug text-ink-3">{note}</p>
    </div>
  );
}
