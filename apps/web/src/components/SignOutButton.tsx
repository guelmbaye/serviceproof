"use client";

import { useFormStatus } from "react-dom";

import { signOut } from "@/actions/auth";

function Button() {
  const { pending } = useFormStatus();

  return (
    <button type="submit" className="u-eyebrow whitespace-nowrap hover:text-ink" disabled={pending}>
      {pending ? "Signing out…" : "Sign out"}
    </button>
  );
}

export function SignOutButton() {
  return (
    <form action={signOut}>
      <Button />
    </form>
  );
}
