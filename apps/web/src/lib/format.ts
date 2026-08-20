export function ms(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  if (value < 1000) return `${value} ms`;
  return `${(value / 1000).toFixed(value < 10000 ? 2 : 1)} s`;
}

export function percent(value: number | null | undefined, digits = 0): string {
  if (value === null || value === undefined) return "—";
  return `${(value * 100).toFixed(digits)}%`;
}

export function clock(value: string | null | undefined): string {
  if (!value) return "—";
  return new Date(value).toLocaleTimeString("en-GB", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
}

export function stamp(value: string | null | undefined): string {
  if (!value) return "—";
  return new Date(value).toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export function ago(value: string | null | undefined): string {
  if (!value) return "—";

  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);

  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.round(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
  return `${Math.round(seconds / 86400)}d ago`;
}

/** Turn LOCATION_VERIFICATION into "Location verification". */
export function humanise(value: string | null | undefined): string {
  if (!value) return "—";
  const lower = value.toLowerCase().replace(/_/g, " ");
  return lower.charAt(0).toUpperCase() + lower.slice(1);
}

export function coordinate(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return value.toFixed(5);
}

/**
 * Review reason codes, as a sentence.
 *
 * The API stores a code because a code is what you filter and report on. The
 * queue is read by a person deciding what to pick up next, so it gets the
 * sentence. Unknown codes fall back to a readable form rather than vanishing.
 */
export function reviewReason(code: string | null | undefined): string {
  if (!code) return "";

  return (
    {
      DISPUTED_EVIDENCE: "A network signal conflicts with the claim.",
      UNVERIFIED: "No usable network evidence came back.",
      PARTIAL_ASSURANCE: "Some evidence supports the claim, but the policy is unmet.",
      AGENT_FAILURE: "The verification run did not complete.",
      GUARD_OVERRIDE: "The guard overrode the agent's proposed decision.",
    }[code] ?? humanise(code)
  );
}
