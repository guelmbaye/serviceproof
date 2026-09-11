import { ApiError, Unauthenticated, api } from "@/lib/api";
import { stamp } from "@/lib/format";
import { Empty, Panel } from "@/components/Panel";
import { redirect } from "next/navigation";

type NetworkEvent = {
  id: string;
  event_id: string | null;
  event_type: string;
  label: string;
  spec_version: string | null;
  source: string | null;
  provider: string;
  subscription_id: string | null;
  device: string | null;
  occurred_at: string | null;
  received_at: string | null;
  payload: Record<string, unknown>;
};

export default async function NetworkEventsPage() {
  let events: NetworkEvent[] = [];

  try {
    const body = await api<{ data: NetworkEvent[] }>("/network-events");
    events = body.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    if (!(error instanceof ApiError)) throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">Push capabilities</p>
        <h1 className="mt-1 text-[26px] font-semibold leading-tight">Network events</h1>
        <p className="mt-2 max-w-2xl text-[14px] leading-relaxed text-ink-2">
          CAMARA CloudEvents the operator pushed to ServiceProof, through a Geofencing
          subscription on Nokia Network as Code. They arrive on a webhook rather than in
          answer to a question we asked.
        </p>
      </header>

      {/* The distinction this screen exists to make. Without it, a viewer would
          reasonably assume these feed the decision path — and they do not. */}
      <div className="rounded-lg border border-rule bg-panel p-4">
        <p className="u-eyebrow">These are observations, not evidence</p>
        <ul className="mt-2 grid gap-1.5 text-[13px] leading-relaxed text-ink-2">
          <li>
            No verification reads them and no decision is derived from them. Evidence is
            something ServiceProof asked for over an authenticated channel and normalised
            through a tool it controls.
          </li>
          <li>
            Nokia signs nothing, so the endpoint is guarded by an unguessable URL rather than
            by a verified sender. That is enough for an observation and would not be enough
            for evidence.
          </li>
          <li>
            The simulator emits the subscribed event type when a subscription is created,
            regardless of device position. These events prove that push delivery works. They
            do not establish presence, and no duration is derived from them.
          </li>
        </ul>
      </div>

      <Panel eyebrow={`${events.length} received`} title="Delivered CloudEvents">
        {events.length === 0 ? (
          <Empty
            headline="Nothing received yet"
            hint="Register a Geofencing subscription with this deployment's webhook URL as the sink."
          />
        ) : (
          <ul className="divide-y divide-rule-soft">
            {events.map((event) => (
              <li key={event.id} className="grid gap-2 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                  <span className="text-[15px] font-semibold">{event.label}</span>
                  <span className="u-machine text-[12px]">
                    {event.received_at ? stamp(event.received_at) : "—"}
                  </span>
                </div>

                <p className="u-machine break-words text-[11.5px] leading-relaxed text-ink-2">
                  {event.event_type}
                </p>

                <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12px] sm:grid-cols-4">
                  <Fact label="Standard" value="CAMARA" />
                  <Fact label="Provider" value={event.provider} />
                  <Fact label="Device" value={event.device ?? "—"} />
                  <Fact label="Subscription" value={event.subscription_id ?? "—"} />
                </dl>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="u-eyebrow text-[10px]">{label}</dt>
      <dd className="u-machine break-words text-[11.5px] text-ink">{value}</dd>
    </div>
  );
}
