import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { coordinate, stamp } from "@/lib/format";
import type { Paginated, WorkOrder } from "@/lib/types";
import { Empty, Panel } from "@/components/Panel";

export const dynamic = "force-dynamic";
export const metadata = { title: "Work orders" };

export default async function WorkOrdersPage() {
  let orders: WorkOrder[] = [];

  try {
    const response = await tryApi<Paginated<WorkOrder>>("/work-orders");
    orders = response?.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">Scheduled field work</p>
        <h1 className="mt-1 text-[26px] leading-none">Work orders</h1>
      </header>

      <Panel>
        {orders.length === 0 ? (
          <Empty headline="No work orders yet." hint="Run make seed to load the demo tenant." />
        ) : (
          <ul>
            {orders.map((order) => (
              <li key={order.id}>
                <Link href={`/work-orders/${order.id}`} className="row-link">
                  <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span className="u-ref text-[14px]">{order.reference}</span>
                    <span className="text-[13px] text-ink-2">{order.customer ?? "—"}</span>
                    <span className="u-eyebrow ml-auto">{order.status.replace(/_/g, " ")}</span>
                  </div>
                  <p className="mt-1 flex flex-wrap gap-x-3 text-[12px] text-ink-2">
                    <span>{order.site.name ?? "—"}</span>
                    <span className="u-machine">
                      {coordinate(order.site.latitude)}, {coordinate(order.site.longitude)} · r=
                      {order.site.radius_m ?? "—"}m
                    </span>
                    <span className="u-machine ml-auto">{stamp(order.scheduled_at)}</span>
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
