import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { ApiError, Unauthenticated, api } from "@/lib/api";
import { coordinate, stamp } from "@/lib/format";
import type { WorkOrder } from "@/lib/types";
import { Empty, Field, Panel } from "@/components/Panel";
import { StateChip } from "@/components/StateChip";

export const dynamic = "force-dynamic";

export default async function WorkOrderPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  let order: WorkOrder;

  try {
    order = (await api<{ data: WorkOrder }>(`/work-orders/${id}`)).data;
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (error instanceof ApiError && error.status === 404) notFound();
    throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">
          <Link href="/work-orders" className="hover:text-ink">
            Work orders
          </Link>
        </p>
        <h1 className="u-ref mt-1.5 text-[26px] leading-none">{order.reference}</h1>
        <p className="mt-1.5 text-[13px] text-ink-2">
          {order.customer ?? "—"} · {order.service_type ?? "—"}
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-[1fr_1fr]">
        <Panel eyebrow="Where the work was expected" title="Site">
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
            <Field label="Name" value={order.site.name ?? "—"} />
            <Field label="Address" value={order.site.address ?? "—"} />
            <Field
              label="Coordinates"
              value={`${coordinate(order.site.latitude)}, ${coordinate(order.site.longitude)}`}
              mono
            />
            <Field label="Radius" value={`${order.site.radius_m ?? "—"} m`} mono />
          </dl>
          <p className="border-t border-rule-soft px-4 py-3 text-[12px] leading-relaxed text-ink-2">
            The radius is what the network is asked about: is the device inside this circle? A
            larger radius accepts more, and proves less.
          </p>
        </Panel>

        <Panel eyebrow="Assignment" title="Who and when">
          <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
            <Field label="Assigned to" value={order.assigned_to?.name ?? "unassigned"} />
            <Field label="Worker reference" value={order.assigned_to?.reference ?? "—"} mono />
            <Field label="Device" value={order.device?.reference ?? "unmapped"} mono />
            <Field label="Policy" value={order.policy?.key ?? "tenant default"} mono />
            <Field label="Scheduled" value={stamp(order.scheduled_at)} mono />
            <Field label="Risk" value={order.risk_level} mono />
            <Field label="Window opens" value={stamp(order.window.starts_at)} mono />
            <Field label="Window closes" value={stamp(order.window.ends_at)} mono />
          </dl>
        </Panel>
      </div>

      <Panel eyebrow="Submitted against this order" title="Claims">
        {!order.claims || order.claims.length === 0 ? (
          <Empty headline="No claims submitted yet." />
        ) : (
          <ul>
            {order.claims.map((claim) => (
              <li key={claim.id}>
                <Link href={`/claims/${claim.id}`} className="row-link">
                  <div className="flex items-center gap-3">
                    <span className="u-ref text-[14px]">{claim.reference}</span>
                    <span className="u-machine">{stamp(claim.claimed_at)}</span>
                    <span className="ml-auto">
                      {claim.decision ? (
                        <StateChip state={claim.decision.state} />
                      ) : (
                        <span className="u-eyebrow">not yet verified</span>
                      )}
                    </span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {order.description && (
        <Panel eyebrow="Scope" title="Description">
          <p className="p-4 text-[13px] leading-relaxed text-ink-2">{order.description}</p>
        </Panel>
      )}
    </div>
  );
}
