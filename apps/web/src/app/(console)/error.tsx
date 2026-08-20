"use client";

export default function ConsoleError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div className="panel p-8">
      <p className="u-eyebrow text-disputed">Something did not load</p>
      <h1 className="mt-2 text-[22px]">This view could not be rendered</h1>
      <p className="mt-2 max-w-lg text-[13px] leading-relaxed text-ink-2">
        The console reached the API but could not use what came back. Nothing was changed.
      </p>
      <p className="u-machine mt-3">{error.message}</p>
      <button type="button" onClick={reset} className="btn btn-quiet mt-5">
        Try again
      </button>
    </div>
  );
}
