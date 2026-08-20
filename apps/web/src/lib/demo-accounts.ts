/**
 * The seeded demo accounts.
 *
 * This lives in a plain module, not in the server-actions file, because a
 * "use server" module may only export async functions — a constant exported
 * from there is stripped out of the client bundle, and the component that
 * maps over it gets undefined at runtime.
 *
 * It doubles as the allowlist for the one-click buttons. Without one, the
 * `demo` field would let anyone try the seed password against an arbitrary
 * address, turning a convenience into a credential-stuffing endpoint. With
 * it, `demo` can only ever mean one of these three.
 */
export interface DemoAccount {
  label: string;
  email: string;
}

export const DEMO_ACCOUNTS: readonly DemoAccount[] = [
  { label: "Operations", email: "ops@acme-field.test" },
  { label: "Reviewer", email: "review@acme-field.test" },
  { label: "Administrator", email: "admin@acme-field.test" },
];

/** The password every seeded account shares. Demo data only. */
export const DEMO_PASSWORD = "password";
