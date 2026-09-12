# Demo video — frozen cut

Built to the §54 timing, which is the one that ships. It differs from the earlier cut in two
ways that matter: Run B now escalates to **Device Swap** rather than Device Status, and two
beats at the end were missing entirely — the real Geofencing CloudEvent and the commercial
close.

**326 spoken words ≈ 2 min 20 s at 140 wpm.** The remaining forty-odd seconds are clicks and
the two live runs. Target 2:50–2:56 on export, never 3:00.

---

## The frozen beats

| | |
|---|---|
| 0:00 – 0:20 | Problem and product thesis |
| 0:20 – 0:55 | Run A — location supports, stop, VERIFIED, CLOSE |
| 0:55 – 1:45 | Run B — location conflicts, Device Swap, stop, DISPUTED, HOLD |
| 1:45 – 2:10 | Side-by-side proof |
| 2:10 – 2:30 | Evidence provenance and Nokia / CAMARA |
| 2:30 – 2:42 | Pushed network events — optional |
| 2:42 – 2:56 | Commercial value and close |

## Before you record

Reseed and re-subscribe in one command: `make demo-reset`. Both halves matter — a reseed drops the received network events, and Nokia only delivers an initial event when a subscription is created. Leave both WO-1042
and WO-1043 unverified — both runs happen live and each completes in under a second.

**Record on production.** The droplet sits beside the `p-eu` gateway; from a laptop the same
runs take five times longer and contradict the deck.

**Prepare two windows** for the 1:45 beat, snapped side by side, each on its claim, scrolled
to the top. Do not build that arrangement on camera.

**For the 2:30 beat**, the console now receives these events itself. Before recording,
register a Geofencing subscription with this deployment's webhook URL as the sink and confirm
an event has landed on **Network events** — `initialEvent: true` delivers one within seconds.
If nothing arrives, cut the beat rather than narrating an empty screen.

Browser 1920×1080, zoom 100 %, no bookmarks, one tab, notifications off.

---

## The script

### 0:00 – 0:20 · Problem and thesis

**Screen** — `/work-orders`, three rows visible. Do not click.

> Enterprises close, pay and audit field-service jobs on self-reported completion. **A claim
> is not evidence.**
>
> ServiceProof asks the mobile network instead. An AI agent decides what evidence a claim
> needs, calls CAMARA capabilities through Nokia Network as Code, and a deterministic policy
> turns the result into a business decision. **The AI doesn't create the evidence. It decides
> how to collect it.**

*(62 words · ~27 s — the longest beat; do not rush the two bold sentences)*

### 0:20 – 0:55 · Run A

**Screen** — CLM-1042. Click **Verify with network evidence**. Say nothing for the second it
takes. Do not scroll: the frame already holds the verdict on the left and the run panel on
the right.

> The policy requires location consistency, so the agent starts there. The signal is
> consistent, the policy is satisfied, and the agent stops.
>
> **One network call out of a budget of three.** Verified. Close the service.

*(44 words · ~19 s, plus the run)*

### 0:55 – 1:45 · Run B

**Screen** — CLM-1043. Click **Verify**. Say nothing while it runs. Then walk the tape with
the cursor.

> Same service claim, same policy, different network evidence.
>
> This time location conflicts with the expected site. The agent does not accuse anyone — it
> asks a narrower question: is the device behind this subscription still the same one? Device
> Swap says a recent change occurred.
>
> **Two calls out of three. It stopped because nothing further could reconcile the conflict,
> not because it ran out.**
>
> Location says elsewhere, continuity says the binding changed. Enough to hold the claim, not
> enough to close it. Disputed. Human review.

*(89 words · ~38 s, plus the run)*

### 1:45 – 2:10 · The side-by-side proof

**Screen** — the two prepared windows, side by side, both scrolled to the top.

> Same service claim. Same policy. Different network evidence.
>
> One network call and close, against two network calls and hold. Different agent plan,
> different business outcome.

*(28 words · ~12 s — say it slowly, then two seconds of silence)*

### 2:10 – 2:30 · Provenance

**Screen** — back to one window on CLM-1043, cursor on the conflicting evidence card's fact
row.

> Every decision traces to its evidence: the CAMARA standard, the capability, Nokia as
> provider, the operator's own correlation id, the latency, and whether the answer came from
> the live network, the Nokia simulator, or a labelled fallback.

*(41 words · ~18 s)*

### 2:30 – 2:42 · Pushed network events — optional

**Screen** — **Network events** in the rail. The received CloudEvent is on screen, with the
three-line panel above it.

> ServiceProof also receives events the operator pushes. These are real CAMARA CloudEvents,
> delivered by Nokia to our own endpoint.
>
> **They are stored as observations, never as evidence.** No verification reads them. The
> simulator emits on subscription rather than on movement, so we derive no duration from
> them — what they prove is that the product works with push capabilities too.

*(56 words · ~24 s — cut this beat first if you overrun)*

### 2:42 – 2:56 · Commercial value and close

**Screen** — the overview dashboard, then **Sign out** to land on the login page.

> Routine claims close without a reviewer. Contested ones reach a person with the evidence
> already gathered.
>
> **ServiceProof turns Open Gateway network intelligence into operational trust.**

*(28 words · ~12 s)*

**Total: 326 spoken words — 2 min 20 s of speech, leaving 36 s for clicks and the two live runs.**

---

## If you overrun

1. The pushed-events beat at 2:30 — 56 words, and the spec marks it optional.
2. The provenance sentence at 2:10 — trim to the correlation id alone.
3. The second half of the thesis at 0:00.

Never cut Run B or the side-by-side.

## The two tests that decide whether it works

**Mute it.** The screen must still show one claim verified after one call, another disputed
after two, and a human review opened. `1 / 3` beside `2 / 3` is the proof — if a viewer
cannot read those, zoom the run panel.

**Close your eyes.** The narration must stand alone. Every sentence above states what
happened and why it matters, and none of them says "as you can see".

## What not to say

**Not "same claim".** Two different work orders on two different devices; a judge could
fairly read "same claim" as the identical transaction. **Same service claim, same policy,
different network evidence** is both stronger and exactly true.

**Not "the technician swapped the phone to cheat".** A swap is a continuity concern, never a
conclusion about a person. People replace broken handsets.

**Not "47-minute dwell", not "we track presence over time".** The simulator never
demonstrated movement and the product does not claim it.
