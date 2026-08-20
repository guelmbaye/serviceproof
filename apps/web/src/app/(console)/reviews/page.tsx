import Link from "next/link";
import { redirect } from "next/navigation";

import { Unauthenticated, tryApi } from "@/lib/api";
import { ago, reviewReason } from "@/lib/format";
import type { Paginated, Review } from "@/lib/types";
import { Empty, Panel } from "@/components/Panel";
import { StateChip } from "@/components/StateChip";

export const dynamic = "force-dynamic";
export const metadata = { title: "Review queue" };

export default async function ReviewsPage({
  searchParams,
}: {
  searchParams: Promise<{ status?: string }>;
}) {
  const { status = "OPEN" } = await searchParams;

  let reviews: Review[] = [];

  try {
    // pending_only defaults to true server-side, so the resolved tab has to
    // switch it off explicitly or it filters itself down to nothing.
    const query =
      status === "RESOLVED" ? "pending_only=0&status=RESOLVED" : "pending_only=1";

    const response = await tryApi<Paginated<Review>>(`/reviews?${query}`);
    reviews = response?.data ?? [];
  } catch (error) {
    if (error instanceof Unauthenticated) redirect("/login");
    throw error;
  }

  return (
    <div className="grid gap-6">
      <header>
        <p className="u-eyebrow">Claims the network could not settle</p>
        <h1 className="mt-1 text-[26px] leading-none">Review queue</h1>
      </header>

      <nav className="flex gap-1.5">
        {[
          ["OPEN", "Open"],
          ["RESOLVED", "Resolved"],
        ].map(([value, label]) => (
          <Link
            key={value}
            href={`/reviews?status=${value}`}
            className="rail-link px-3"
            data-active={status === value}
          >
            {label}
          </Link>
        ))}
      </nav>

      <Panel>
        {reviews.length === 0 ? (
          <Empty
            headline={
              status !== "RESOLVED"
                ? "Nothing is waiting on a reviewer."
                : "No reviews have been resolved yet."
            }
            hint={status !== "RESOLVED" ? "Disputed and partial claims arrive here." : undefined}
          />
        ) : (
          <ul>
            {reviews.map((review) => (
              <li key={review.id}>
                <Link href={`/reviews/${review.id}`} className="row-link">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    {review.decision && <StateChip state={review.decision.state} />}
                    <span className="u-ref text-[14px]">
                      {review.claim?.work_order?.reference ?? review.claim?.reference}
                    </span>
                    <span className="text-[13px] text-ink-2">
                      {review.claim?.work_order?.customer ?? "—"}
                    </span>
                    <span className="u-machine ml-auto">
                      {review.outcome === "CONFIRMED" ? "upheld" : review.outcome ? "overridden" : ago(review.created_at)}
                    </span>
                  </div>
                  {review.reason && (
                    <p className="mt-1 text-[12px] leading-snug text-ink-2">
                      {reviewReason(review.reason)}
                    </p>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}
