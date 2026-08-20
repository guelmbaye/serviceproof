/**
 * The four decision states, and the five evidence statuses, share one chip.
 * Colour is carried by the [data-state] attribute so the token lives in CSS
 * and nothing here has to know a hex value.
 */
export function StateChip({ state, title }: { state: string; title?: string }) {
  return (
    <span className="state-chip" data-state={state} title={title}>
      {state.replace(/_/g, " ")}
    </span>
  );
}

/**
 * Grey is a deliberate choice, not a missing colour: an unavailable API is
 * an absence of evidence, not evidence against the claim, and the interface
 * should not flag it like a failure.
 */
export function StateLegend() {
  const entries: [string, string][] = [
    ["VERIFIED", "Evidence supports the claim. Close it."],
    ["PARTIAL", "Support exists, policy requirements incomplete."],
    ["DISPUTED", "A signal conflicts. A human reconciles it."],
    ["UNVERIFIED", "No usable evidence. An absence, not an accusation."],
  ];

  return (
    <dl className="grid gap-2">
      {entries.map(([state, meaning]) => (
        <div key={state} className="flex items-start gap-3">
          <dt className="w-[104px] flex-none sm:w-28">
            <StateChip state={state} />
          </dt>
          <dd className="text-[12px] text-ink-2">{meaning}</dd>
        </div>
      ))}
    </dl>
  );
}
