export default function Loading() {
  return (
    <div className="grid gap-4">
      <div className="pulse">
        <p className="u-eyebrow">Loading</p>
      </div>
      <div className="panel h-32" />
      <div className="grid gap-4 lg:grid-cols-[1.35fr_1fr]">
        <div className="panel h-64" />
        <div className="panel h-64" />
      </div>
    </div>
  );
}
