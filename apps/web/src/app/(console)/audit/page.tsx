import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { clock, humanise, stamp } from "@/lib/format";
import type { Paginated } from "@/lib/types";
import { Empty, Panel } from "@/components/Panel";

export const dynamic = "force-dynamic";
export const metadata = { title: "Audit log" };

/** Mirrors AuditEventResource exactly. */
interface AuditEvent {
  id: string;
  event_type: string;
  actor: { type: string | null; name?: string | null } | null;
  resource: { type: string | null; id: string | null } | null;
  metadata: Record<string, unknown> | null;
  request_id: string | null;
  occurred_at: string | null;
}

/** App\Domain\Claims\Models\Claim -> Claim */
function shortClass(value: string | null | undefined): string | null {
  if (!value) return null;
  const parts = value.split("\\");
  return parts[parts.length - 1] || value;
}

function metaString(event: AuditEvent, key: string): string | null {
  const value = event.metadata?.[key];
  return typeof value === "string" && value.length > 0 ? value : null;
}

/** The case an event belongs to, if it belongs to one. */
function subject(event: AuditEvent): string | null {
  return (
    metaString(event, "claim_reference") ??
    metaString(event, "work_order_reference") ??
    metaString(event, "reference")
  );
}

/** The one detail worth reading on the row, beyond the event type. */
function detail(event: AuditEvent): string | null {
  const meta = event.metadata ?? {};

  for (const key of ["state", "decision_state", "outcome", "status", "type", "role"]) {
    const value = meta[key];
    if (typeof value === "string" && value) return value;
  }

  return null;
}

/**
 * A run of consecutive events that belong together.
 *
 * Laravel stamps every event in one HTTP request with the same request id, so
 * a single verification writes a dozen rows under one id. Presented flat they
 * are unreadable — twelve near-identical lines with no way to see where one
 * case ends and the next begins. Grouping turns the same rows into a handful
 * of sessions a person can scan.
 */
interface Group {
  key: string;
  requestId: string | null;
  subject: string | null;
  actor: string;
  events: AuditEvent[];
}

function group(events: AuditEvent[]): Group[] {
  const groups: Group[] = [];

  for (const event of events) {
    const previous = groups[groups.length - 1];
    const sameRequest =
      previous && event.request_id !== null && previous.requestId === event.request_id;

    if (previous && sameRequest) {
      previous.events.push(event);
      if (!previous.subject) previous.subject = subject(event);
      continue;
    }

    groups.push({
      key: event.id,
      requestId: event.request_id,
      subject: subject(event),
      actor: event.actor?.name ?? event.actor?.type?.toLowerCase() ?? "system",
      events: [event],
    });
  }

  return groups;
}

export default async function AuditPage() {
  let events: AuditEvent[] = [];

  try {
    const response = await tryApi<Paginated<AuditEvent>>("/audit?per_page=60");
    events = response?.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  const groups = group(events);

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">Append-only</p>
        <h1 className="mt-1 text-[26px] leading-none">Audit log</h1>
        <p className="mt-2 max-w-2xl text-[13px] leading-relaxed text-ink-2">
          Every state change is recorded here and nothing in it can be edited or deleted. The
          question &ldquo;why did the system believe this on Tuesday?&rdquo; has an answer that
          survives the disagreement.
        </p>
      </header>

      {groups.length === 0 ? (
        <Panel>
          <Empty headline="No audit events recorded yet." />
        </Panel>
      ) : (
        <div className="grid gap-3">
          {groups.map((entry) => (
            <Panel key={entry.key}>
              <header className="panel-head">
                <div className="min-w-0">
                  <p className="u-eyebrow">
                    {entry.actor}
                    {entry.requestId && ` · request ${entry.requestId.slice(0, 8)}`}
                  </p>
                  <h2 className="u-ref mt-0.5 text-[15px] leading-tight">
                    {entry.subject ?? humanise(entry.events[0].event_type)}
                  </h2>
                </div>
                <p className="u-machine flex-none whitespace-nowrap">
                  {stamp(entry.events[0].occurred_at)}
                  <span className="text-ink-3">
                    {" "}
                    · {entry.events.length} event{entry.events.length === 1 ? "" : "s"}
                  </span>
                </p>
              </header>

              <ol>
                {entry.events.map((event) => {
                  const value = detail(event);
                  const resource = shortClass(event.resource?.type);

                  return (
                    <li
                      key={event.id}
                      className="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-rule-soft px-4 py-2.5 last:border-b-0"
                    >
                      {/* Fixed width only once there is room for it: at 168px
                          on a phone the event type takes half the row and the
                          rest wraps under it. */}
                      <span className="u-eyebrow sm:w-[168px] sm:flex-none">
                        {event.event_type.replace(/_/g, " ")}
                      </span>
                      <span className="min-w-0 flex-1 text-[13px]">
                        {resource ?? "—"}
                        {value && <span className="text-ink-2"> · {value}</span>}
                      </span>
                      <span className="u-machine flex-none">{clock(event.occurred_at)}</span>
                    </li>
                  );
                })}
              </ol>
            </Panel>
          ))}
        </div>
      )}

      <p className="text-[12px] text-ink-2">
        Grouped by request: everything written while handling one action shares an id.{" "}
        <Link href="/claims" className="text-signal-ink hover:underline">
          Open a claim
        </Link>{" "}
        to see the same run as an evidence tape.
      </p>
    </div>
  );
}
