# The operations console

Next.js 15, App Router, TypeScript, Tailwind v4. Server components fetch Laravel
directly; the browser never holds a token and never talks to the API.

## What the screen is for

An operations manager opening a disputed claim has one job: decide whether to defend the
decision or overturn it. Everything on the screen is arranged around that.

So the console is not a metrics dashboard with a claims list bolted on. The claim detail
page is the product, and it answers three questions in order:

1. **What was decided, and how confident is that?** — the verdict panel and the assurance
   meter.
2. **What is it resting on?** — the evidence cards, each with full provenance.
3. **Why did the agent do that?** — the evidence tape.

## Session handling

The bearer token lives in an httpOnly cookie set by a server action. Every call to Laravel
is made server-side. An XSS payload in this app has nothing to steal, and the API base URL
never reaches the client bundle.

Mutations are server actions, not route handlers: `runVerification` and `resolveReview`
call Laravel, then `revalidatePath` the affected screens. Verification is synchronous
because the backend is — one bounded round trip, so the console waits rather than polls.

Every action takes `(prevState, formData)` and reads its record id from a hidden input.
Passing the id with `action.bind(null, id)` looks tidier and costs a whole class of bug:
React namespaces the form's named inputs so it can reconstruct the bound argument list, so
a field submitted as `notes` arrives as `_1_notes` and `formData.get("notes")` reads
nothing. On a required field that surfaces as a validation error nobody can explain; on an
optional one it fails silently, which is how it survived unnoticed in the verify panel.

## Two design decisions worth defending

**UNVERIFIED is grey.**

The obvious palette gives four states four colours: green, amber, red, and a second red for
the worst one. That would be wrong. `UNVERIFIED` means no usable evidence came back — an
absence, not an accusation. Colouring it like a failure teaches operations teams to read a
network outage as a fraud signal, which is exactly the behaviour this product exists to
prevent. So it is the one state with no colour at all, and the legend on the overview says
why in one line.

**The assurance score is drawn as its own formula.**

The score is `0.40 completeness + 0.35 consistency + 0.15 freshness + 0.10 availability`.
Rather than render that as a number with a donut chart, the meter draws four segments whose
*widths* are the weights and whose *fills* are the measured values. The number is the area.

A reviewer who disagrees with a 41 can point at the short segment and say which part they
dispute. That is a different conversation from "the AI gave it 41".

## The signature: the evidence tape

The agent's trace is a sequence of *actions*, not a stream of thought, so it is drawn like
an instrument tape rather than a chat log:

- one continuous rule down the left, drawn on load
- a hairline tick for a routine event
- a hollow node where a tool was actually called
- a rotated notch where the agent broke off to escalate — the one moment the tape visibly
  breaks
- latency drawn at true proportion, 1px per 20ms

Read WO-1042's tape and WO-1043's tape side by side and the product's central claim is
visible without anyone narrating it: same code, same policy, one call versus two, and the
notch showing exactly where the second one was decided.

The tape deliberately shows *what the agent did*, never what a model "thought". There is no
chain-of-thought in the API and none on the screen.

## Brand

The mark is a shield containing a transmitter and a check — the network vouching for the
work — and its blue is the interface's accent. `--color-signal` is `#0060fc`, sampled from
the artwork rather than chosen alongside it, so a button and the logo above it are the same
colour rather than nearly the same.

One split was necessary. That blue measures exactly 4.50:1 against the paper background,
which is the AA threshold rather than a margin, so small text uses `--color-signal-ink`
(`#0050d8`, 5.85:1) while fills, rules and the evidence tape keep the brand value. White on
the brand blue clears 5.15:1, so buttons need no adjustment.

The rail carries the mark alone, not the full lockup: at 224px of rail the wordmark would
render around 50px tall and the shield detail would turn to mud. The lockup appears on the
sign-in screen, where there is room for it and where it is the first thing a judge sees.

Favicon, apple touch icon and social card are resolved by Next from `icon.png`,
`apple-icon.png` and `opengraph-image.png` sitting beside the root layout — no hand-written
`<link>` tags to drift out of sync.

## Typography

Three faces, each with a job:

| Role | Face | Used for |
|---|---|---|
| Display | Archivo | headings, references, figures |
| Body | Public Sans | prose, labels, rationale |
| Data | IBM Plex Mono | request ids, timestamps, coordinates, latency, hashes |

The rule is that **machine facts are set in machine type**. A request id, an observed
timestamp and a latency reading are things the network asserted; they look different from
things a person wrote. In a product whose whole argument is provenance, that distinction
earns its place.

## Writing

Errors state what happened and what to do, in the interface's voice. The 404 page says a
record outside your organisation is simply not there — which is also literally how the
backend behaves, since tenancy is a query scope and cross-tenant ids return 404 rather
than 403.

Demo affordances are labelled as demo affordances. The scenario selector on the verify
panel says in plain text that pinning an outcome only affects the simulated adapter and
cannot change how live evidence is read.

## Running it

```bash
cd apps/web
cp .env.example .env.local     # API_BASE_URL=http://localhost:8000/api/v1
npm install
npm run dev                    # http://localhost:3000
```

Or through the stack: `make up`, then open http://localhost:3000 and sign in as
`ops@acme-field.test` / `password`.

```bash
npm run typecheck              # tsc --noEmit
npm run build                  # production build
```

## Not built

- No field-worker view. Claim submission is API-complete and exercised by `make smoke`,
  but the worker-facing app is Flutter on the roadmap, not part of this console.
- No admin CRUD screens. Users, devices and policies are readable here and writable
  through the API; the forms were not worth the hackathon hours against the demo.
- No live trace streaming. The internal endpoint exists on the backend; the console reads
  the completed trace, which is enough while runs finish in seconds.
