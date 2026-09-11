import { humanise, ms } from "@/lib/format";
import type { Evidence } from "@/lib/types";
import { StateChip } from "@/components/StateChip";

/**
 * One piece of network evidence.
 *
 * Every card leads with the business question the API was asked, because
 * "Was the device consistent with the expected site?" is what a reviewer is
 * actually adjudicating — "Location Verification returned FALSE" is only how
 * we found out.
 *
 * Provenance is not an expandable detail. It sits on the face of the card:
 * no request id, no evidence.
 */
export function EvidenceCard({ evidence }: { evidence: Evidence }) {
  const { provenance: p } = evidence;

  return (
    <article
      className="panel verdict p-4"
      data-state={evidence.status}
      style={{ borderLeftWidth: "3px" }}
    >
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="u-eyebrow">{humanise(evidence.type)}</p>
          <h3 className="mt-0.5 text-[14px] leading-snug">{evidence.business_question}</h3>
        </div>
        <StateChip state={evidence.status} />
      </header>

      {evidence.summary && (
        <p className="mt-2.5 text-[13px] leading-relaxed text-ink-2">{evidence.summary}</p>
      )}

      {evidence.failure_reason && (
        <p className="u-machine mt-2 border-l-2 border-rule pl-2">{evidence.failure_reason}</p>
      )}

      {evidence.simulated && (
        <p className="u-eyebrow mt-2.5 text-partial">
          simulated · this item did not come from the live network
        </p>
      )}

      <dl className="mt-3 grid grid-cols-2 items-start gap-x-4 gap-y-2.5 border-t border-rule-soft pt-3 sm:grid-cols-4">
        <Fact label="API" value={p.api ?? "—"} />
        {/* Spec §30: name the standard, not just the endpoint. On a CAMARA
            hackathon the word is the point — it says this is an
            interoperable capability rather than one vendor's API. */}
        <Fact label="Standard" value="CAMARA" />
        <Fact label="Provider" value={p.provider ?? "—"} />
        <Fact label="Latency" value={ms(p.latency_ms)} />
        <Fact
          label="Reliability"
          value={evidence.reliability !== null ? evidence.reliability.toFixed(2) : "—"}
        />
        <Fact label="Observed" value={p.observed_at ? new Date(p.observed_at).toISOString().slice(11, 19) : "—"} />
        <Fact label="Age" value={p.age_seconds !== null ? `${p.age_seconds}s` : "—"} />
        <Fact label="Freshness" value={p.freshness ?? "—"} />
        <Fact label="Source" value={p.source_label} />
      </dl>

      {p.request_id && (
        <p className="u-machine mt-2.5 border-t border-rule-soft pt-2.5">
          <span className="text-ink-3">request id </span>
          {p.request_id}
        </p>
      )}
    </article>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="u-eyebrow truncate">{label}</dt>
      {/* Values wrap rather than truncate. "Location Verification" and "Live
          network evidence" both overflow a quarter-width column at this size,
          and a provenance record that hides half the API name defeats itself —
          the whole point of the row is that a reviewer can read it. */}
      <dd className="u-machine leading-snug break-words">{value}</dd>
    </div>
  );
}
