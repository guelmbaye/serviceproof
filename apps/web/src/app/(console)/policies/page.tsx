import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { humanise, ms } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { Empty, Field, Panel } from "@/components/Panel";

export const dynamic = "force-dynamic";
export const metadata = { title: "Policies" };

interface Policy {
  id: string;
  key: string;
  name: string;
  description: string | null;
  assurance_level: string;
  required_evidence: string[] | null;
  optional_evidence: string[] | null;
  /** Nested in the API resource — not two top-level fields. */
  evidence_budget: { max_tool_calls: number | null; max_latency_ms: number | null } | null;
  location_radius_m: number | null;
  freshness_seconds: number | null;
  allow_partial: boolean;
  auto_close_on_verified: boolean;
  allowed_tools: string[] | null;
  is_active: boolean;
}

export default async function PoliciesPage() {
  let policies: Policy[] = [];

  try {
    const response = await tryApi<Paginated<Policy>>("/policies");
    policies = response?.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">What counts as sufficient</p>
        <h1 className="mt-1 text-[26px] leading-none">Verification policies</h1>
        <p className="mt-2 max-w-2xl text-[13px] leading-relaxed text-ink-2">
          A policy is the contract between an operations team and the agent: which signals are
          required, how many API calls may be spent, and whether incomplete evidence may still
          close a claim. The agent chooses what to gather. The policy decides what it means.
        </p>
      </header>

      {policies.length === 0 ? (
        <Panel>
          <Empty headline="No policies configured." hint="Run make seed to load the demo tenant." />
        </Panel>
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {policies.map((policy) => (
            <Panel
              key={policy.id}
              eyebrow={policy.key}
              title={policy.name}
              aside={
                policy.is_active ? null : <span className="u-eyebrow text-ink-3">inactive</span>
              }
            >
              {policy.description && (
                <p className="px-4 pt-3 text-[12px] leading-relaxed text-ink-2">
                  {policy.description}
                </p>
              )}

              <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
                <Field label="Assurance level" value={policy.assurance_level} mono />
                <Field
                  label="Evidence budget"
                  value={
                    policy.evidence_budget?.max_tool_calls
                      ? `${policy.evidence_budget.max_tool_calls} calls`
                      : "—"
                  }
                  mono
                />
                <Field label="Latency budget" value={ms(policy.evidence_budget?.max_latency_ms)} mono />
                <Field label="Default radius" value={`${policy.location_radius_m ?? "—"} m`} mono />
                <Field
                  label="Freshness window"
                  value={policy.freshness_seconds ? `${policy.freshness_seconds}s` : "—"}
                  mono
                />
                <Field label="Partial allowed" value={policy.allow_partial ? "yes" : "no"} mono />
                <Field
                  label="Auto-close"
                  value={policy.auto_close_on_verified ? "on verified" : "never"}
                  mono
                />
              </dl>

              <div className="border-t border-rule-soft p-4">
                <p className="u-eyebrow">Required</p>
                <p className="mt-1 text-[12px]">
                  {(policy.required_evidence ?? []).map(humanise).join(" · ") || "none"}
                </p>
                <p className="u-eyebrow mt-3">Available on escalation</p>
                <p className="mt-1 text-[12px] text-ink-2">
                  {(policy.optional_evidence ?? []).map(humanise).join(" · ") || "none"}
                </p>
                <p className="u-eyebrow mt-3">Tools the agent may call</p>
                <p className="u-machine mt-1">
                  {(policy.allowed_tools ?? []).join(" · ") || "none"}
                </p>
              </div>
            </Panel>
          ))}
        </div>
      )}
    </div>
  );
}
