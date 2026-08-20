"use server";

import { redirect } from "next/navigation";

import { ApiError, api, login as loginRequest } from "@/lib/api";
import { DEMO_ACCOUNTS, DEMO_PASSWORD } from "@/lib/demo-accounts";
import { clearToken, getToken, setToken } from "@/lib/session";

export interface LoginState {
  error?: string;
}

export async function signIn(_previous: LoginState, formData: FormData): Promise<LoginState> {
  // A one-click demo button submits the account it stands for.
  const requested = formData.get("demo");
  const demo =
    typeof requested === "string"
      ? DEMO_ACCOUNTS.find((account) => account.email === requested)
      : undefined;

  if (typeof requested === "string" && requested && !demo) {
    return { error: "That is not one of the demo accounts." };
  }

  const email = demo?.email ?? String(formData.get("email") ?? "").trim();
  const password = demo ? DEMO_PASSWORD : String(formData.get("password") ?? "");

  if (!email || !password) {
    return { error: "Enter an email and a password." };
  }

  try {
    const result = await loginRequest(email, password);
    await setToken(result.token, result.expires_at);
  } catch (error) {
    if (error instanceof ApiError) return { error: error.message };
    return { error: "The API is not reachable. Check that the stack is running on port 8000." };
  }

  redirect("/overview");
}

export async function signOut(): Promise<void> {
  const token = await getToken();

  if (token) {
    // Best effort: the local cookie is cleared either way.
    await api("/auth/logout", { method: "POST", token }).catch(() => null);
  }

  await clearToken();
  redirect("/login");
}
