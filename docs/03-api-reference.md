# API reference

Base URL: `http://localhost:8000/api/v1`
Auth: `Authorization: Bearer <token>` (Laravel Sanctum), obtained from `POST /auth/login`.

Every response carries `X-Request-Id`. Errors use one envelope:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "...", "details": {}, "request_id": "..." } }
```

## Auth

| Method | Path | Notes |
|---|---|---|
| `POST` | `/auth/login` | `{ email, password, device_name? }` → token + user + organisation |
| `POST` | `/auth/logout` | revokes the current token |
| `GET` | `/auth/me` | current user, role, organisation |

## Work orders

| Method | Path | Roles |
|---|---|---|
| `GET` | `/work-orders` | all — filters: `status`, `open_only`, `assigned_user_id`, `search` |
| `POST` | `/work-orders` | `ORG_ADMIN`, `OPERATIONS_MANAGER` |
| `GET` | `/work-orders/{id}` | all (own tenant) |
| `PATCH` | `/work-orders/{id}` | `ORG_ADMIN`, `OPERATIONS_MANAGER` |
| `POST` | `/work-orders/{id}/assign` | `ORG_ADMIN`, `OPERATIONS_MANAGER` |

## Claims and verification

| Method | Path | Notes |
|---|---|---|
| `POST` | `/work-orders/{id}/claims` | a field worker submits a completion claim. Accepts `idempotency_key` |
| `GET` | `/claims` | filters: `status`, `decision`, `work_order_id` |
| `GET` | `/claims/{id}` | includes the current decision and, for ops roles, the evidence |
| `POST` | `/claims/{id}/verify` | **runs the agent.** Optional `force_mode`, `scenario` |

`POST /claims/{id}/verify` returns the completed run:

```json
{
  "data": {
    "id": "…", "status": "COMPLETED",
    "decision": {
      "state": "DISPUTED",
      "recommended_action": "ESCALATE",
      "assurance_score": 41,
      "rationale": "1 signal(s) materially conflict with the claim…",
      "guard_applied": true
    },
    "evidence": [ { "type": "LOCATION_VERIFICATION", "status": "CONFLICTING", "source": "CAMARA", "request_id": "…", "summary": "…" } ],
    "tool_calls_used": 2,
    "escalated": true,
    "used_demo_fallback": false,
    "duration_ms": 1840
  }
}
```

## Evidence, trace, reviews

| Method | Path | Notes |
|---|---|---|
| `GET` | `/verifications` | run history |
| `GET` | `/verifications/{id}` | one run with evidence and decision |
| `GET` | `/verifications/{id}/trace` | the ordered agent action trace |
| `GET` | `/verifications/health` | agent reachability, planner mode, CAMARA mode |
| `GET` | `/claims/{id}/evidence` | ops roles only |
| `GET` | `/evidence/{id}` | one immutable evidence item with full provenance |
| `GET` | `/reviews` | the review queue (`REVIEWER`, `OPERATIONS_MANAGER`, `ORG_ADMIN`) |
| `POST` | `/reviews/{id}/assign` | claim a review |
| `POST` | `/reviews/{id}/resolve` | writes a **new** HUMAN decision and supersedes the old one |

Resolving a review takes exactly these fields. `CONFIRMED` is what the API calls upholding
a decision — the interface says "uphold", the record says `CONFIRMED`:

```json
{
  "outcome": "CONFIRMED | OVERRIDDEN",
  "override_state": "VERIFIED | PARTIAL | DISPUTED | UNVERIFIED",
  "notes": "at least 3 characters"
}
```

`override_state` is required when and only when the outcome is `OVERRIDDEN`. `notes` is
always required: a manual override with no stated reason is not auditable, and the note is
what a reviewer six months later actually reads.

## Dashboard, policies, admin

| Method | Path | Notes |
|---|---|---|
| `GET` | `/dashboard` | counts by decision state, escalation rate, avg duration, illustrative ROI |
| `GET` | `/policies` · `POST` · `PATCH` | verification policies (`ORG_ADMIN`) |
| `GET` | `/users` · `POST` | tenant user management (`ORG_ADMIN`) |
| `GET` | `/devices` · `POST` · `PATCH` | device ↔ worker mappings (`ORG_ADMIN`) |
| `GET` | `/audit` | the tenant audit log (`ORG_ADMIN`) |
| `GET` | `/admin/organizations` … | platform operator surface (`SUPER_ADMIN`) |

## Internal channel

`POST /internal/agent/trace` — the agent may stream trace events during a long run.
Protected by `X-Internal-Token`, never exposed publicly. Not required for the main flow:
the standard path returns the whole trace in the verify response.

## Agent runtime (internal, port 9000)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/health` | planner provider, CAMARA mode, tool catalogue |
| `GET` | `/tools` | the controlled tool surface with business questions |
| `POST` | `/agent/verify` | requires `X-Internal-Token`. Schemas in `packages/contracts/` |

## CLI

```bash
php artisan serviceproof:verify WO-1042            # verify the latest claim on a work order
php artisan serviceproof:verify WO-1043 --scenario=DISPUTED
```
