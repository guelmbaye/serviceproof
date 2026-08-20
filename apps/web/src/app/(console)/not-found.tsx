import Link from "next/link";

export default function NotFound() {
  return (
    <div className="panel p-8">
      <p className="u-eyebrow">404</p>
      <h1 className="mt-2 text-[22px]">That record is not in this organisation</h1>
      <p className="mt-2 max-w-lg text-[13px] leading-relaxed text-ink-2">
        Either it does not exist, or it belongs to another tenant. ServiceProof does not confirm
        the difference — a record outside your organisation is simply not there.
      </p>
      <Link href="/overview" className="btn btn-quiet mt-5 w-fit">
        Back to the overview
      </Link>
    </div>
  );
}
