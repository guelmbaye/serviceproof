# Security, privacy and trust

## Data minimisation

The AI layer sees as little as the job allows.

- Workers reach the agent as a **pseudonymous reference** (`WKR-004`). No name, no email,
  no phone number, no employee record.
- The device identifier is sent because CAMARA requires it to answer, and for nothing else.
- Raw provider payloads are **not** shipped upward. The agent keeps a hash and the
  normalised shape needed to defend the decision.
- Worker free text is passed only where it is needed for planning, inside an explicit
  untrusted-data fence.

## Prompt injection

Field workers write notes. Notes reach a planner. That is an injection surface, and it is
treated as one.

Three layers:

1. **Delimitation.** Worker text is fenced in `<untrusted_worker_notes>` and the system
   prompt states that it is data which can never instruct, change the policy, or authorise
   a decision.
2. **Validation.** The planner's only structured output is a tool name, checked against the
   allowed tool list before anything runs. An invented tool, a forbidden tool, or a
   repeated signal is refused and the deterministic planner takes over.
3. **Containment.** Even a fully compromised planner cannot produce a verdict, because the
   verdict is not its output. `DecisionGuard` re-derives the state from persisted evidence.

`tests/test_orchestrator.py::test_worker_notes_cannot_instruct_the_agent` and
`tests/test_planner_guardrails.py` pin all three.

## Authentication and authorisation

- Sanctum bearer tokens; the agent runtime is not publicly routable and requires a shared
  `X-Internal-Token`.
- Five roles: `SUPER_ADMIN`, `ORG_ADMIN`, `OPERATIONS_MANAGER`, `REVIEWER`, `FIELD_WORKER`.
- Seven policy classes. A field worker can submit a claim and see its outcome; they cannot
  see the evidence behind it.
- Tenancy is enforced by a global query scope, so a missing `where` clause in a future
  controller cannot leak another tenant's data.

## Immutability and audit

`Evidence` and `AuditEvent` throw on update and delete. Decisions are superseded, never
edited. Every state change — claim submitted, run opened, evidence recorded, decision
proposed, guard applied, human override — is written to the audit log with actor, tenant,
request id and timestamp.

The question "why did the system believe this on Tuesday?" has an answer that cannot be
edited after the fact. That is the difference between a demo and something an operator's
compliance team will let through the door.

## Honest degradation

Every failure mode was chosen to produce a *defensible* answer rather than a confident
wrong one:

| Failure | Result |
|---|---|
| CAMARA times out | `UNAVAILABLE` evidence → `UNVERIFIED`, never `DISPUTED` |
| LLM unreachable or returns junk | deterministic planner, reported as `llm_fallback_heuristic` |
| Agent runtime crashes | run marked `FAILED`, claim never auto-verified |
| Live credentials missing | labelled demo evidence, `INCLUDES_SIMULATED_EVIDENCE` on the score |
| Agent proposes a state the evidence doesn't support | guard overrides, divergence recorded |

Fail closed, every time. A system that says "I don't know" is worth deploying. A system
that guesses confidently is not.

## What we would add before production

Named plainly, because pretending otherwise is worse than admitting it:

- Consent capture and worker-facing transparency on what the network is asked, per
  jurisdiction. This is a legal requirement in most of the markets this product targets,
  and it is a UI and workflow problem, not a technical one.
- Per-tenant encryption keys for device identifiers at rest.
- Rate limiting and quota accounting per organisation on the CAMARA layer.
- Signed evidence bundles, so a customer dispute can be settled without trusting our
  database.
- A retention policy: evidence has a shelf life, and keeping it forever is a liability
  rather than an asset.
