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

## Permission before evidence

Knowing an MSISDN does not confer the right to query it. The binding between a worker, a
device and the organisation either authorises a network query or it does not, and the agent
enforces that rather than assuming the product core already did.

The check runs **before planning**, so a refusal costs zero API calls. That ordering is the
whole point: an authorisation gate that fires after the request has gone out is a formality,
not a control. The trace shows `ENTITLEMENT_VERIFIED` ahead of the first `TOOL_CALLED`, or
`ENTITLEMENT_REFUSED` and nothing after it.

A refusal is `UNVERIFIED`, never `DISPUTED`. An authorisation gap says nothing about whether
the technician did the work — it says we were not permitted to look. Turning it into evidence
against a person is exactly the failure this product exists to avoid.

It is deliberately thin. Full consent management, per-jurisdiction lawful basis and
revocation workflows are scoped and not built, and the documentation says so rather than
implying a compliance story the code does not deliver.

## Why not just use GPS

GPS may remain part of enterprise evidence, and nothing here argues for removing it. What
CAMARA gives ServiceProof is an **independent operator-derived evidence channel** — a signal
the party being paid does not control.

That is the whole distinction, and it is why no build time is spent integrating GPS: adding
a second self-reported source would not change what the product can say.

## What the evidence does not prove

The product's strongest objection is that a device inside a geofence is not a person doing
work. It is a fair objection and the answer is not to argue with it.

So a VERIFIED verdict carries its own limitation on the card where it is read: network
evidence sufficiently supports the claim under the configured policy, and it is supporting
evidence rather than independent proof that the physical task was completed. The location
summary says *verification radius*, never *accuracy* — operator positioning varies from
hundreds of metres to kilometres with network density and technology, and the radius is what
we asked about, not what the network can resolve.

The three source modes are named rather than implied: **Live network** for a subscriber,
**Nokia NaC simulator** for a number in ITU's reserved +999 test range, and **ServiceProof
demo fallback · simulated** for our own adapter. A real call about a test device is not a
live measurement, and a judge who recognises the range would be right to say so.

Recommended actions name the consequence rather than the workflow step: *Close and proceed*,
*Hold · human review opened*. What a business reads is what happens to the payment.

## Escalation stops when nothing further would help

A conflicting primary signal blocks automatic verification whatever else agrees. One
corroborating call is worth making — it separates "the device was elsewhere" from "the
network could not see the device at all" — and a second is worth none.

So the agent spends one, then stops with budget to spare, and the run panel says so:
*Stopped: nothing further would help*. Leaving budget unspent is the point. An agent that
runs to its ceiling looks like a loop completing; an agent that stops early because more
evidence could not change the answer is reasoning about evidence utility, which is the thing
worth demonstrating.

## Immutability

`Evidence` and `AuditEvent` throw on update and delete. A reviewer who disagrees with a
decision does not overwrite it: `ReviewService` writes a **new** decision with
`origin = HUMAN` and marks the previous one `superseded_by`. The history of what the
system believed, and when, is never rewritten.
