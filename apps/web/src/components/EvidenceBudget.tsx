/**
 * Minimum sufficient evidence, made visible.
 *
 * The agent's defining behaviour is not that it calls APIs — anything can call
 * an API. It is that it decides how many to call, and stops. A clean claim
 * costs one call; a contested one costs three, because the first answer
 * conflicted. Showing the budget as a number among other numbers hid that.
 *
 * It also has to survive being watched with the sound off: on a recorded demo,
 * "1 of 3" beside "3 of 3" is the proof, and it needs to be legible at a
 * glance rather than read.
 */
export function EvidenceBudget({
  used,
  max,
  escalated,
  resolved,
}: {
  used: number;
  max: number;
  escalated: boolean;
  /** Did the escalation actually settle the claim? */
  resolved: boolean;
}) {
  const slots = Array.from({ length: Math.max(max, used) }, (_, i) => i < used);

  // Derived from what happened, not from a stored label: the loop either
  // stopped early because the policy was satisfied, spent everything it had,
  // or escalated and then stopped.
  // Wording matters here. "Budget exhausted" reads as a loop that ran out,
  // when what happened is an agent that chose to escalate and found the
  // conflict still standing. The first is a failure; the second is the
  // behaviour the product exists to demonstrate.
  const [status, why] = !escalated
    ? [
        "Minimum sufficient evidence",
        "The policy was satisfied by the first signal. The agent stopped.",
      ]
    : resolved
      ? [
          "Escalated, then satisfied",
          "A signal was insufficient, so the agent gathered more before deciding.",
        ]
      : used < max
        ? [
            // The strongest of the four, and the one worth showing a judge:
            // budget was left on the table because spending it could not have
            // changed the answer.
            "Stopped: nothing further would help",
            "A signal conflicted and corroboration did not reconcile it. No remaining capability could satisfy the requirement, so the agent stopped with budget to spare.",
          ]
        : [
            "Escalated to the ceiling",
            "A signal conflicted. The agent gathered every corroborating signal its budget allowed, and the conflict stood.",
          ];

  return (
    <div className="flex flex-wrap items-center gap-x-6 gap-y-3 p-4">
      <div className="flex items-baseline gap-1.5">
        <span className="u-ref text-[34px] leading-none">{used}</span>
        <span className="u-machine text-[15px]">/ {max}</span>
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex gap-1" aria-hidden>
          {slots.map((filled, i) => (
            <span
              key={i}
              className={`h-1.5 w-8 rounded-full ${filled ? "bg-signal" : "bg-rule"}`}
            />
          ))}
        </div>
        <p className="u-eyebrow mt-2">{status}</p>
        <p className="mt-0.5 text-[12px] leading-snug text-ink-2">{why}</p>
      </div>
    </div>
  );
}
