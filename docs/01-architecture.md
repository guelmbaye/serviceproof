# Architecture

> Laravel owns the product. FastAPI owns the agent.
> CAMARA provides the network evidence. PostgreSQL owns the truth.

## Why two runtimes

A single service would have been simpler to start and worse to defend. The split is
deliberate and each side is chosen for what it is actually good at.

**Laravel (product core)** holds everything that must survive a restart, an audit, or a
disagreement: identity, tenancy, work orders, claims, immutable evidence, decisions,
reviews, the audit log. It is the system of record and the only thing that talks to
PostgreSQL.

**FastAPI (agent runtime)** holds everything that reasons: evidence planning, tool
selection, escalation, and the CAMARA access layer. It is **stateless**. It can be
restarted mid-demo and nothing is lost, because it never owned anything.

The practical consequence: the intelligence layer can fail, hang, or return nonsense,
and the product still behaves correctly. That property is what makes this deployable
rather than a demo.

## Request flow

```
  Field app / Ops console
          │  POST /api/v1/claims/{claim}/verify
          ▼
  ┌───────────────────────────────────────────────┐
  │ Laravel — product core                        │
  │  1. authorise + resolve tenant                │
  │  2. open VerificationRun                      │
  │  3. build the full context bundle from PG     │
  └───────────────┬───────────────────────────────┘
                  │  ONE POST, everything pre-loaded
                  │  X-Internal-Token
                  ▼
  ┌───────────────────────────────────────────────┐
  │ FastAPI — agent runtime (stateless)           │
  │  4. plan the evidence                         │
  │  5. select the minimum sufficient tool        │
  │  6. call CAMARA ───────────► Nokia NaC        │
  │  7. normalise + evaluate                      │
  │  8. escalate only if it could change things   │
  │  9. propose a decision                        │
  └───────────────┬───────────────────────────────┘
                  │  evidence + trace + proposal
                  ▼
  ┌───────────────────────────────────────────────┐
  │ Laravel — DecisionGuard                       │
  │ 10. persist evidence (immutable)              │
  │ 11. RE-DERIVE the decision from that evidence │
  │ 12. route to review if needed                 │
  │ 13. write the audit trail                     │
  └───────────────────────────────────────────────┘
```

### Why one round trip

An earlier design had the agent call back into Laravel for context as it needed it.
That produces N round trips inside one user-facing request, and the first slow CAMARA
call pushes the whole thing past the timeout. So Laravel pre-loads the entire bundle —
claim, work order, expected site, device mapping, policy, budget — and sends it once.
The agent needs no callback. The cycle stays inside a single bounded request.

## The trust boundary

The most important line in the system sits between step 9 and step 11.

The agent **proposes**. `DecisionGuard` **disposes**. Laravel re-derives the final state
deterministically from the evidence that was actually persisted, using the same rules the
agent applied — and if the two disagree, the guard wins and the divergence is recorded.

This means no prompt, no jailbreak, and no model failure can produce a `VERIFIED` claim
that the persisted evidence does not support. The LLM cannot talk the system into
anything. It can only decide *what to go and look at*.

## Layers inside the agent

| Layer | Responsibility | Contains AI? |
|---|---|---|
| `app/agent/planner.py` | which evidence, is one more call worth it | yes (validated) |
| `app/tools/` | the fixed, narrow tool surface | no |
| `app/camara/` | Nokia NaC calls, demo fallback, normalisation | no |
| `app/policies/evaluator.py` | is this sufficient, what state does it imply | **no** |

The evaluator is deliberately dumb. Everything a reviewer has to defend in front of a
customer is produced by explicit rules, not by a model.

## Tenancy

Isolation is enforced at the data layer, not the UI layer. Every tenant-scoped model uses
the `BelongsToOrganization` trait, which installs a global Eloquent scope driven by a
request-scoped `TenantContext`. A cross-tenant identifier does not return a 403 — it
returns a 404, because in that tenant's world the record does not exist.

## Recovering a stranded verification

`openRun()` moves the claim to `VERIFYING` before the agent is called, and
`canStartVerification()` refuses that state. Anything that throws in between —
a bug, an agent timeout, a container killed mid-request — would leave the claim
there permanently: a transient failure making a record unverifiable for good.

Two guards close that off. Every run is wrapped, and a failure marks the run
`FAILED` and hands the claim back to `SUBMITTED`. And a claim already found in
`VERIFYING` is reclaimed if its open run started longer ago than the agent
timeout plus a minute — nothing legitimate runs that long, so such a run has no
process behind it. Within the window the claim is still refused, because
reclaiming a run that is genuinely in flight would let two verifications write
to the same claim.

The failed run is kept either way. A verification that did not complete is part
of the claim's history, and deleting it is the kind of tidying this system
exists to prevent.

## Immutability

`Evidence` and `AuditEvent` throw on update and delete. A reviewer who disagrees with a
decision does not overwrite it: `ReviewService` writes a **new** decision with
`origin = HUMAN` and marks the previous one `superseded_by`. The history of what the
system believed, and when, is never rewritten.
