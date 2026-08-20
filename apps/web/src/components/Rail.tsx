"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const LINKS: { href: string; label: string; short: string; countKey?: "reviews" }[] = [
  { href: "/overview", label: "Overview", short: "Overview" },
  { href: "/claims", label: "Claims", short: "Claims" },
  { href: "/work-orders", label: "Work orders", short: "Orders" },
  { href: "/reviews", label: "Review queue", short: "Reviews", countKey: "reviews" },
  { href: "/policies", label: "Policies", short: "Policies" },
  { href: "/audit", label: "Audit log", short: "Audit" },
];

export function Rail({ openReviews }: { openReviews: number }) {
  const pathname = usePathname();

  return (
    <nav
      /* A scrollable row of tabs on a narrow screen, the sidebar list from lg
         up. Stacked vertically the six links cost around 250px before any
         content appears, which on a phone is most of the first screen.
         The negative margin lets the row bleed to the edges so the last tab
         is visibly cut off — that is the affordance telling you it scrolls. */
      className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 lg:mx-0 lg:grid lg:gap-0.5 lg:overflow-visible lg:px-0 lg:pb-0"
      aria-label="Sections"
    >
      {LINKS.map((link) => {
        const active = pathname === link.href || pathname.startsWith(`${link.href}/`);

        return (
          <Link
            key={link.href}
            href={link.href}
            className="rail-link"
            data-active={active}
            aria-current={active ? "page" : undefined}
          >
            {/* The long label needs the sidebar's width; the tab row gets the
                short one so six of them stay reachable without scrolling. */}
            <span className="lg:hidden">{link.short}</span>
            <span className="hidden lg:inline">{link.label}</span>
            {link.countKey === "reviews" && openReviews > 0 && (
              <span className="rail-count">{openReviews}</span>
            )}
          </Link>
        );
      })}
    </nav>
  );
}
