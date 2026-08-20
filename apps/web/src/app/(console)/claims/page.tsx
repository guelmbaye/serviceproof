import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { ago } from "@/lib/format";
import type { Claim, Paginated } from "@/lib/types";
import { Empty, Panel } from "@/components/Panel";
import { StateChip } from "@/components/StateChip";

export const dynamic = "force-dynamic";
export const metadata = { title: "Claims" };

const FILTERS = [
  ["", "All"],
  ["VERIFIED", "Verified"],
  ["PARTIAL", "Partial"],
  ["DISPUTED", "Disputed"],
  ["UNVERIFIED", "Unverified"],
];

export default async function ClaimsPage({
  searchParams,
}: {
  searchParams: Promise<{ state?: string }>;
}) {
  const { state } = await searchParams;

  let claims: Claim[] = [];

  try {
    const response = await tryApi<Paginated<Claim>>(
      `/claims${state ? `?decision=${state}` : ""}`,
    );
    claims = response?.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">Service claims</p>
        <h1 className="mt-1 text-[26px] leading-none">Claims</h1>
      </header>

      <nav className="flex flex-wrap gap-1.5">
        {FILTERS.map(([value, label]) => (
          <Link
            key={label}
            href={value ? `/claims?state=${value}` : "/claims"}
            className="rail-link px-3"
            data-active={(state ?? "") === value}
          >
            {label}
          </Link>
        ))}
      </nav>

      <Panel>
        {claims.length === 0 ? (
          <Empty
            headline="No claims match this filter."
            hint="A field worker submits a claim against a work order."
          />
        ) : (
          <ul>
            {claims.map((claim) => (
              <li key={claim.id}>
                <Link href={`/claims/${claim.id}`} className="row-link">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="u-ref text-[14px]">{claim.reference}</span>
                    <span className="text-[13px] text-ink-2">
                      {claim.work_order?.reference} · {claim.work_order?.customer ?? "—"}
                    </span>
                    <span className="ml-auto flex items-center gap-3">
                      {claim.decision ? (
                        <StateChip state={claim.decision.state} />
                      ) : (
                        <span className="u-eyebrow">not yet verified</span>
                      )}
                      <span className="u-machine w-14 flex-none text-right sm:w-16">{ago(claim.created_at)}</span>
                    </span>
                  </div>
                  <p className="mt-1 text-[12px] text-ink-2">
                    {claim.work_order?.site ?? "—"}
                    {claim.worker?.reference && (
                      <span className="text-ink-3"> · worker {claim.worker.reference}</span>
                    )}
                  </p>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}
