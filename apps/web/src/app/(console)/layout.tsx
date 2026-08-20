import Image from "next/image";
import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import type { SessionUser } from "@/lib/types";
import { Rail } from "@/components/Rail";
import { SignOutButton } from "@/components/SignOutButton";

export default async function ConsoleLayout({ children }: { children: React.ReactNode }) {
  let me: SessionUser | null = null;
  let openReviews = 0;

  try {
    const response = await tryApi<{ data: SessionUser }>("/auth/me");
    me = response?.data ?? null;

    if (me?.capabilities.can_review) {
      const reviews = await tryApi<{ data: unknown[]; meta?: { total: number } }>(
        "/reviews?pending_only=1",
      );
      openReviews = reviews?.meta?.total ?? reviews?.data?.length ?? 0;
    }
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  if (!me) redirect("/login");

  return (
    <div className="mx-auto flex min-h-dvh max-w-[1400px] flex-col lg:flex-row">
      {/* Sticky on every size, but on a phone it is a ~90px header rather
          than a 450px column: identity and sign-out move onto the logo row,
          and the six links become a scrollable tab strip. */}
      <aside className="sticky top-0 z-20 flex-none border-b border-rule bg-panel px-4 py-3 lg:flex lg:h-dvh lg:w-[224px] lg:flex-col lg:border-r lg:border-b-0 lg:py-4">
        <div className="flex items-center justify-between gap-3">
          {/* The mark alone, not the full lockup: at 224px of rail the
              wordmark would render around 50px tall and the shield detail
              would turn to mud. */}
          <Link href="/overview" className="flex min-w-0 items-center gap-2.5">
            <Image
              src="/logo-mark.png"
              alt=""
              width={128}
              height={128}
              className="h-7 w-7 flex-none"
            />
            <span className="min-w-0">
              <span className="u-ref block text-[15px] leading-none">ServiceProof</span>
              {/* Below 640px the header has three competing items and this
                  one is the least useful — the mark already says where you
                  are. Hiding it stops the other two from wrapping. */}
              <span className="u-eyebrow mt-1 hidden sm:block">Operations console</span>
            </span>
          </Link>

          {/* Identity sits inline up here while the screen is narrow. */}
          <div className="flex min-w-0 items-center gap-3 lg:hidden">
            <span className="min-w-0 text-right">
              <span className="block truncate text-[12px] font-medium">{me.name}</span>
              <span className="u-eyebrow hidden truncate sm:block">{me.role.replace(/_/g, " ")}</span>
            </span>
            <SignOutButton />
          </div>
        </div>

        {/* The only part that scrolls.
            `min-h-0` is what makes it work: a flex child refuses to shrink
            below its content by default, so without it the column simply grows
            past the viewport and overflow-y-auto never engages. On a short
            laptop screen that left the last links unreachable — no scrollbar,
            just gone. */}
        <div className="rail-scroll mt-3 lg:mt-6 lg:min-h-0 lg:flex-1 lg:overflow-y-auto">
          <Rail openReviews={openReviews} />
        </div>

        {/* Pinned to the bottom by the flex column rather than by absolute
            positioning, which would have scrolled away with the links. */}
        <div className="mt-6 hidden border-t border-rule-soft pt-4 lg:block lg:flex-none">
          <p className="truncate text-[13px] font-medium">{me.name}</p>
          <p className="u-machine truncate">{me.organization?.name ?? "—"}</p>
          <div className="mt-2 flex items-center justify-between gap-2">
            <span className="u-eyebrow">{me.role.replace(/_/g, " ")}</span>
            <SignOutButton />
          </div>
        </div>
      </aside>

      <main className="min-w-0 flex-1 px-4 py-6 sm:px-5 lg:px-8 lg:py-8">{children}</main>
    </div>
  );
}
