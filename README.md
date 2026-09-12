<img src="docs/assets/logo.png" alt="ServiceProof AI" width="420">

**Prove the service. Trust the evidence.**

An AI agent that decides *what network evidence to gather* to establish whether a
field-service claim actually happened — and a deterministic core that decides what that
evidence means.

Built for the GSMA MENA Ignite Hackathon on CAMARA APIs via Nokia Network as Code.

---

## The problem

A technician marks a job complete. A contractor invoices for it. Somewhere between those
two events sits a claim that nobody can cheaply verify.

Today the industry verifies it with photos, GPS pins from the worker's own app, and
timesheets — all of which are supplied by the party with the incentive to be believed. So
enterprises do one of two things: pay and hope, or run manual spot-checks that cost more
than the disputes they prevent.

The one signal in this problem that is *expensive to fake* already exists. The mobile
network knows where a device was and whether it was connected. Operators own it. They just
don't sell it as assurance.

## What this is

ServiceProof turns a service claim into a **decision backed by network evidence**:

```
VERIFIED     the evidence supports the claim, close it
PARTIAL      supporting evidence exists, policy requirements incomplete
DISPUTED     a signal materially conflicts, a human must reconcile it
UNVERIFIED   no usable evidence — an absence, not an accusation
```

Two things make it more than an API wrapper.

**It is genuinely agentic.** The agent plans what evidence would be sufficient, calls one
API, evaluates what came back, and escalates to a second signal *only if that could change
the outcome*. WO-1042 costs one call. WO-1043 costs two — not because someone hardcoded a
branch, but because the first signal came back conflicting.

**The AI never makes the decision.** The model's only output is which tool to call next.
Laravel's `DecisionGuard` re-derives the final state from the evidence that was actually
persisted. No prompt, no jailbreak, and no model failure can produce a `VERIFIED` claim the
evidence does not support. There is a test for exactly that.

---

## Quickstart

```bash
git clone <repo> && cd serviceproof
make init          # .env, build, composer install, migrate, seed  (~3 min)
make smoke         # login → submit claim → verify, end to end
```

| Service | URL |
|---|---|
| Ops console (Next.js) | http://localhost:3000 |
| Product API (Laravel) | http://localhost:8000/api/v1 |
| Agent runtime (FastAPI) | http://localhost:9000/docs |

The field app runs outside Docker, on an emulator or a handset:

```bash
make mobile-setup     # generates the Flutter platform folders, once
make mobile-run       # against the local stack
```

Sign in as `ops@acme-field.test` / `password`.

**No API keys required.** With `LLM_PROVIDER=heuristic` and `CAMARA_MODE=demo` the entire
system runs offline through the deterministic planner and the labelled demo adapter — same
orchestration loop, same evidence contract, same decision path. Add `GROQ_API_KEY` or
`NAC_RAPIDAPI_KEY` to `.env` to light up the live paths.

### Verify from the CLI

```bash
docker compose exec api php artisan serviceproof:verify WO-1042   # → VERIFIED,   1 call
docker compose exec api php artisan serviceproof:verify WO-1043   # → DISPUTED,   2 calls, escalated
docker compose exec api php artisan serviceproof:verify WO-1044   # → UNVERIFIED, no evidence available
```

---

## Architecture

> Laravel owns the product. FastAPI owns the agent.
> CAMARA provides the network evidence. PostgreSQL owns the truth.

```
Next.js ops console          Flutter field app
      │                            │
      └────────────┬───────────────┘
                   ▼
Laravel 11 ── product core ────────────► PostgreSQL 16
      │   identity · tenancy · claims        (system of record)
      │   immutable evidence · decisions
      │   reviews · audit · DecisionGuard
      │
      │  one POST, full context pre-loaded
      ▼
FastAPI ── agent runtime (stateless) ──► Nokia Network as Code
          evidence planning                  Location Verification
          tool selection · escalation        Device Status
          normalisation · policy eval        Device Reachability
```

The console is server-rendered and holds no token in the browser: the session lives in an
httpOnly cookie and every API call is made server-side, so the client bundle never sees a
credential or the API's address.

The backend split is deliberate. The agent runtime holds no durable state, so it can be
restarted mid-demo and nothing is lost. The intelligence layer can fail, hang, or return nonsense and
the product still behaves correctly — which is what makes this deployable rather than a
demo.

Details: [`docs/01-architecture.md`](docs/01-architecture.md)

---

## Repository

```
apps/api/          Laravel 11 — product core       131 PHP files, 23 tests
apps/agent/        FastAPI — agent runtime          75 tests
apps/web/          Next.js 15 — operations console   13 routes, builds clean
apps/mobile/       Flutter — field technician app    offline-first, 25 tests
packages/contracts/  JSON Schema for the agent contract
infra/docker/      Dockerfiles — development and production
infra/production/  Production compose, nginx, supervisor, entrypoint
docs/              architecture, CAMARA, API, runbook, security
```

## Documentation

| | |
|---|---|
| [Architecture](docs/01-architecture.md) | why two runtimes, the trust boundary, tenancy, immutability |
| [CAMARA integration](docs/02-camara-integration.md) | tools not buttons, normalisation rules, the honest fallback |
| [API reference](docs/03-api-reference.md) | every endpoint, request and response shapes |
| [Demo runbook](docs/04-demo-runbook.md) | the three scenarios, failure playbook, judge Q&A |
| [Security and trust](docs/05-security-and-trust.md) | prompt injection, data minimisation, what's missing |
| [Operations console](docs/06-operations-console.md) | the web frontend, and why UNVERIFIED is grey |
| [Field app](apps/mobile/README.md) | the technician's app, the offline outbox, and why it has no GPS |
| [Demo video script](docs/07-demo-video-script.md) | the three-minute cut, beat by beat, with what must be on screen |
| [Deployment](docs/guide-deploy-serviceproof.md) | DigitalOcean, shared Nginx proxy, production images (in French) |

---

## Design decisions worth defending

**An unavailable API is never negative evidence.** A timeout, a 429, or an `UNKNOWN`
result produces `UNAVAILABLE`, never `CONFLICTING`. A system that forgets this accuses
honest technicians of fraud every time an API has a bad afternoon.

**Only contemporaneous signals can conflict.** `NOT_CONNECTED` observed now says little
about a job completed three hours ago. Outside the window it is `STALE`, not evidence
against the claim.

**No provenance, no evidence.** Every usable item carries the API name, the provider
request id, the observed timestamp, the latency and a payload hash. Items arriving without
provenance are rejected at the recorder.

**Simulated evidence is never presented as live.** The demo adapter produces
CAMARA-shaped payloads so the code path is identical — and tags every item `DEMO_FALLBACK`
all the way to the UI, with `INCLUDES_SIMULATED_EVIDENCE` on the assurance score.

**Nothing is overwritten.** A reviewer who disagrees creates a *new* decision with
`origin = HUMAN`; the original is marked superseded. Evidence and audit events throw on
update and delete.

**The assurance score is explainable, not vibes.**
`0.40 completeness + 0.35 consistency + 0.15 freshness + 0.10 availability`, with the
component values returned alongside the number.

---

## Testing

```bash
make test                                    # both backend suites
make typecheck                               # the console
make mobile-test                             # the field app
docker compose exec api php artisan test     # Laravel: flow, tenancy, guard, immutability
docker compose exec agent pytest -q          # agent: orchestration, guardrails, normalisation
```

The tests that matter most:

- `DecisionGuardTest` — the agent cannot obtain `VERIFIED` against conflicting evidence
- `test_worker_notes_cannot_instruct_the_agent` — prompt injection still ends `DISPUTED`
- `test_planner_guardrails.py` — invented, forbidden and repeated tool calls are refused
- `TenantIsolationTest` — a cross-tenant id returns 404, not 403
- `EvidenceIntegrityTest` — evidence throws on update and on delete
- `apps/api/tool/match_check.py` — every `match ($this)` on an enum handles every
  case. PHP throws UnhandledMatchError at runtime, never at build, so adding a case
  and forgetting one of three match statements in the same file broke one run on the
  deployed system while the other kept working
- `apps/agent/tool/contract_check.py` — every field the agent's schema expects is
  produced somewhere in Laravel. Written after shipping an entitlement gate before
  the field that feeds it, which refused every verification on the deployed system
- `apps/api/tool/closure_check.py` — no closure reads an outer variable it did
  not capture, which `php -l` cannot see and which shipped a broken run once
- `ReferenceGeneratorTest` — references order numerically past WO-9999, and two
  concurrent submissions cannot mint the same number
- `test_nac_endpoints.py` — the exact Nokia URL, headers and body for every capability,
  so a refactor cannot silently break a live demo
- `test_normalizer.py` — a timestamp with no timezone, as Nokia actually sends it, and a
  crashing tool still leaves a readable evidence record
- `outbox_test.dart` — a retried claim keeps its idempotency key, and the field app
  never puts its own coordinates in the request body

---

## Known limits

Named plainly, because pretending otherwise is worse.

- **Consent capture is not built.** Asking the network about a worker's device needs a
  lawful basis and worker-facing transparency in most target markets. That is a workflow
  and legal problem we have scoped, not solved.
- **The ROI figures on the dashboard are illustrative**, driven by configurable
  assumptions, and labelled as such in the API response. They are not measured outcomes.
- **Single-region deployment.** Multi-operator federation is an adapter change by design,
  but it has not been built or tested.
- **The field app has not been compiled.** It is structurally checked and its logic is
  unit-tested, but no Flutter toolchain was available where it was written. Run
  `make mobile-setup && make mobile-test` before demoing it.
- **English only.** For MENA deployment, Arabic and French with RTL is not optional.

---

## Licence

Prepared for the GSMA MENA Ignite Hackathon.
