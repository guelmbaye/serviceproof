import "server-only";

import { cookies } from "next/headers";

const COOKIE = process.env.SESSION_COOKIE_NAME ?? "sp_session";

/**
 * The bearer token lives in an httpOnly cookie and never reaches client
 * JavaScript. Every call to Laravel is made from the server, so a stolen
 * XSS payload has nothing to steal.
 */
export async function getToken(): Promise<string | null> {
  const jar = await cookies();
  return jar.get(COOKIE)?.value ?? null;
}

export async function setToken(token: string, expiresAt?: string | null): Promise<void> {
  const jar = await cookies();

  jar.set(COOKIE, token, {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    ...(expiresAt ? { expires: new Date(expiresAt) } : { maxAge: 60 * 60 * 12 }),
  });
}

export async function clearToken(): Promise<void> {
  const jar = await cookies();
  jar.delete(COOKIE);
}
