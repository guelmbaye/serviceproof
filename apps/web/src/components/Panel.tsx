import type { ReactNode } from "react";

export function Panel({
  title,
  eyebrow,
  aside,
  children,
  className = "",
}: {
  title?: string;
  eyebrow?: string;
  aside?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={`panel ${className}`}>
      {(title || eyebrow || aside) && (
        <header className="panel-head">
          <div>
            {eyebrow && <p className="u-eyebrow">{eyebrow}</p>}
            {title && <h2 className="text-[15px] leading-tight">{title}</h2>}
          </div>
          {aside}
        </header>
      )}
      {children}
    </section>
  );
}

export function Empty({ headline, hint }: { headline: string; hint?: string }) {
  return (
    <div className="px-4 py-10 text-center">
      <p className="text-[13px] text-ink-2">{headline}</p>
      {hint && <p className="u-machine mt-1">{hint}</p>}
    </div>
  );
}

export function Field({ label, value, mono = false }: { label: string; value: ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="u-eyebrow">{label}</dt>
      <dd className={mono ? "u-machine mt-0.5" : "mt-0.5 text-[13px]"}>{value}</dd>
    </div>
  );
}
