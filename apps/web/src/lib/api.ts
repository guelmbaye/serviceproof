import "server-only";

import { getToken } from "@/lib/session";

const BASE = process.env.API_BASE_URL ?? "http://localhost:8000/api/v1";

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly details?: Record<string, string[]>,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/** Thrown when the session is gone. Callers redirect rather than render an error. */
export class Unauthenticated extends Error {}

interface Options {
  method?: string;
  body?: unknown;
  token?: string;
  /** Some panels are optional context — a failure there should not blank the page. */
  tolerateFailure?: boolean;
}

export async function api<T>(path: string, options: Options = {}): Promise<T> {
  const token = options.token ?? (await getToken());

  if (!token) throw new Unauthenticated("No session.");

  const response = await fetch(`${BASE}${path}`, {
    method: options.method ?? "GET",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: options.body ? JSON.stringify(options.body) : undefined,
    cache: "no-store",
  });

  if (response.status === 401) throw new Unauthenticated("Session expired.");

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const error = payload?.error ?? {};
    throw new ApiError(
      response.status,
      error.message ?? `Request failed with status ${response.status}.`,
      error.details,
    );
  }

  return payload as T;
}

/** Unauthenticated login call — the only path that runs without a token. */
export async function login(email: string, password: string) {
  const response = await fetch(`${BASE}/auth/login`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ email, password, device_name: "ops-console" }),
    cache: "no-store",
  });

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    const error = payload?.error ?? {};
    const firstDetail = error.details ? Object.values(error.details)[0] : null;
    throw new ApiError(
      response.status,
      (Array.isArray(firstDetail) ? firstDetail[0] : null) ??
        error.message ??
        "Sign-in failed. Check the email and password.",
    );
  }

  return payload as {
    token: string;
    expires_at: string | null;
    user: { data?: unknown } & Record<string, unknown>;
  };
}

/** Optional context: returns null instead of throwing, so a panel can degrade. */
export async function tryApi<T>(path: string): Promise<T | null> {
  try {
    return await api<T>(path);
  } catch (error) {
    if (error instanceof Unauthenticated) throw error;
    return null;
  }
}
