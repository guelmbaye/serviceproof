import Image from "next/image";
import { redirect } from "next/navigation";

import { getToken } from "@/lib/session";
import { SignInForm } from "@/app/login/SignInForm";
import { StateLegend } from "@/components/StateChip";

export const metadata = { title: "Sign in" };

export default async function LoginPage() {
  if (await getToken()) redirect("/overview");

  return (
    <main className="mx-auto grid min-h-dvh max-w-5xl items-center gap-10 px-5 py-8 sm:px-6 sm:py-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-12">
      <div>
        <Image
          src="/logo.png"
          alt="ServiceProof AI"
          width={2039}
          height={582}
          priority
          className="h-auto w-[min(300px,70vw)]"
        />
        <h1 className="mt-5 text-[clamp(2rem,4.4vw,3rem)] leading-[1.03]">
          Prove the service.
          <br />
          Trust the evidence.
        </h1>
        <p className="mt-5 max-w-md text-[15px] leading-relaxed text-ink-2">
          Field-service claims are checked against the one signal that is expensive to fake: the
          mobile network. An agent decides what evidence to gather. Deterministic policy decides
          what it means.
        </p>

        {/* The shared legend, not a copy of it. The hand-written version that
            stood here had drifted: three states instead of four, PARTIAL
            missing entirely, and wording that no longer matched the console.
            Four verdicts described in two places is three too many. */}
        <div className="mt-7 max-w-md border-t border-rule pt-5">
          <p className="u-eyebrow">What each verdict means</p>
          <div className="mt-3">
            <StateLegend />
          </div>
        </div>

        {/* Two facts a reviewer arriving cold cannot otherwise know: that this
            deployment calls the operator for real, and what to do once inside.
            Without them the page explains the product and leaves the visitor
            at a form. */}
        <div className="mt-6 max-w-md border-t border-rule pt-5">
          <p className="text-[13px] leading-relaxed text-ink-2">
            This deployment calls{" "}
            <span className="text-ink">Nokia Network as Code</span> for real. Sign in as
            Operations, open a claim, and press{" "}
            <span className="text-ink">Verify with network evidence</span>.
          </p>
          <p className="mt-2 text-[13px] leading-relaxed text-ink-2">
            A routine claim costs one network call. A contested one costs three — not because
            anyone wrote a branch for it, but because the first signal came back conflicting.
          </p>
        </div>
      </div>

      <div className="panel p-6">
        <h2 className="text-[15px]">Sign in</h2>
        <div className="mt-4">
          <SignInForm />
        </div>
      </div>
    </main>
  );
}
