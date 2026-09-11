# CAMARA integration

## The framing that matters

CAMARA APIs are not buttons in this product. They are **tools an agent chooses between**.

The difference shows up the moment a claim is contested. A button-based integration calls
the same three APIs every time, in the same order, and shows you three results. This agent
calls one API when one is enough, and reaches for the second only because the first came
back conflicting — and it says so, in the trace, in a sentence an operations reviewer can
read.

## Capabilities used

| Tool | CAMARA API | Business question |
|---|---|---|
| `verify_location` | Location Verification | Was the device consistent with the expected site? |
| `get_device_status` | Device Status | Was the device active on the network? |
| `check_reachability` | Device Reachability Status | Could the device be reached? |

Location Retrieval and Device Roaming Status are wired in the client and available for the
roadmap, but are not part of the default tool surface — every additional tool is an
additional call to justify.

Access is through **Nokia Network as Code**. `app/camara/client.py`
is the only file in the codebase that knows anything Nokia-specific; everything above it
works with normalised evidence. Adding a second operator is an adapter, not a rewrite.

## Normalisation rules

Raw CAMARA responses become `Evidence` items with one of five statuses. Two rules do most
of the work:

**An absent signal is never a negative signal.** A timeout, a 429, an auth failure, or a
`verificationResult: UNKNOWN` all produce `UNAVAILABLE`. Never `CONFLICTING`. A failed call
tells you nothing about whether the job happened, and a system that forgets this will
accuse honest technicians of fraud every time an API has a bad afternoon.

**Only contemporaneous signals can conflict.** `NOT_CONNECTED` observed now says very
little about a job completed three hours ago. Outside the contemporaneity window it is
recorded as `STALE`, not as evidence against the claim.

| CAMARA result | Status | Reliability |
|---|---|---|
| `verificationResult: TRUE` | `SUPPORTED` | 0.94 |
| `verificationResult: PARTIAL` | `SUPPORTED` | 0.60 |
| `verificationResult: FALSE` | `CONFLICTING` | 0.90 |
| `verificationResult: UNKNOWN` | `UNAVAILABLE` | — |
| `CONNECTED_DATA` / `CONNECTED_SMS` | `SUPPORTED` | 0.85 |
| `NOT_CONNECTED`, in window | `CONFLICTING` | 0.70 |
| `NOT_CONNECTED`, out of window | `STALE` | 0.40 |
| any transport/auth failure | `UNAVAILABLE` | — |

## Device Swap, and why it is the escalation that matters

When location is contested, the obvious next call is Device Status — and it is nearly
useless. "Was the handset attached to the network" is true of almost every handset and
answers nothing about the claim.

Device Swap questions something narrower and directly relevant: whether the identifier we
queried still maps to the same physical device. That is the assumption the whole claim rests
on, so it is the assumption worth testing when the primary signal conflicts.

The wording is load-bearing. A swap is **never** evidence that a technician cheated — people
replace broken phones. The evidence says the binding supporting this claim is not
continuous, and stops there. Whether that matters is the policy layer's call; whether it
means anything about a person is a reviewer's. A test asserts the summary contains none of
*fraud*, *cheat*, *lying*, *suspicious* or *deliberate*.

The escalation order lives in the policy, not in the planner, because it is an operational
judgment rather than an algorithm: `['DEVICE_SWAP', 'DEVICE_STATUS', 'DEVICE_REACHABILITY']`.

> **Nokia mounts Device Swap differently from every other capability here.**
>
> ```
> /passthrough/camara/v1/device-swap/device-swap/v1/check
> ```
>
> A passthrough prefix, and the segment repeated. The body is flat too —
> `{"phoneNumber": "+999…", "maxAge": 240}` rather than CAMARA's `{"device": {…}}`
> envelope. Two departures from the standard, neither guessable, and fixing only the path
> would have turned a 404 into a 400 that looked like a second capability failure.
>
> Both are pinned by wire-contract tests, as the other three capabilities are. The lookback
> window is configurable because "changed recently" is a policy question, not a constant.
>
> **The note that was here before, kept because it was right:** Confirmed on the deployed system:
> `/device-swap/v0/check` does not exist on the Network as Code gateway. The system degrades
> exactly as designed — UNAVAILABLE evidence, never an accusation, with a message saying the
> network cannot answer for this device — but Run B loses the signal its argument rests on.
>
> Run `device-swap-path.sh` to find the real path, then set `NAC_PATH_DEVICE_SWAP`. If no
> path responds, the capability is probably not on the subscription, and the fallback is to
> re-add `DEVICE_STATUS` to the entitlement so the agent has a corroboration it can reach.
>
> **The original note, kept because it was right:** `NAC_PATH_DEVICE_SWAP` defaults to the CAMARA standard
> `/device-swap/v0/check`. Confirm it against the Nokia portal before a live demonstration:
> a wrong path returns 404, which this system correctly records as UNAVAILABLE rather than as
> evidence against anyone — but the signal is lost and the run is weaker for it.
| a 200 we cannot interpret | `UNAVAILABLE`, reason names the fields received | — |

Status values are matched by shape rather than against a fixed list. CAMARA specifies
`CONNECTED_DATA` / `CONNECTED_SMS` / `NOT_CONNECTED`, but the portal's own reachability
example is named `REACHABLE_SMS`, and an operator returning a value one word off the spec
should not silently cost a signal. A negative prefix still wins, so the wider match cannot
turn a refusal into support.

When a 200 genuinely cannot be read, the evidence names the field and the value that came
back. An unrecognised response used to produce `UNAVAILABLE` with an empty reason, which
is the worst thing to hand a reviewer: a signal was lost and the record says nothing about
why.

That diagnostic paid for itself on the first live run. Reachability was coming back 200 and
landing as `UNAVAILABLE`, and the named-fields message said why in one line:

```
reachabilityStatus=absent (fields returned: connectivity, device, lastStatusTime, reachable)
```

Nokia does not send CAMARA's `reachabilityStatus`. It sends a boolean `reachable` with a
`connectivity` channel list and a `lastStatusTime` — the same fact in a different shape.
The normaliser reads that shape first, because it is the one that actually arrives, and
falls back to the spec field. `lastStatusTime` drives freshness for this signal too,
instead of assuming the answer describes the present instant.

`connectivity` is a list: `["SMS"]`, sometimes `["DATA", "SMS"]`. Coercing it with `str()`
put *reachable over `['sms']`* in front of a reviewer — Python syntax leaking into an
evidence record. Provider fields are now coerced deliberately rather than interpolated, and
a test asserts that no generated summary contains a bracket or a quote, because that is
what a stringified container looks like from the outside.

Every usable item carries provenance: the API name, the provider request id, the observed
timestamp, the latency, and a hash of the payload. **No provenance, no evidence** — the
Laravel `EvidenceRecorder` rejects items that arrive without it.

One practical note on timestamps. Nokia returns `lastLocationTime` with no timezone
offset, so it parses to a naive datetime; the normaliser treats any naive value as UTC,
which is what CAMARA specifies for these fields. Because that is an assumption rather than
a guarantee, an observation that lands more than five minutes in our future is taken as
evidence that the assumption was wrong for this operator: the age is reported as unknown
rather than computed from a timestamp we cannot read. A readable verification result is
never discarded over an unreadable clock, and a good observation is never downgraded to
`STALE` on the strength of one.

## Live, demo, and the honest fallback

`CAMARA_MODE` takes three values:

- `live` — live calls only. Failures surface honestly as `UNAVAILABLE` evidence.
- `demo` — the recorded adapter only.
- `auto` — live first, and if the network call fails, the labelled demo adapter.

The fallback is designed around one constraint: it must not lie. It produces payloads in
the **same shape** as real CAMARA responses, so the normaliser, the evidence contract, the
policy evaluator and the decision path are byte-for-byte the same code path as a live run.
The architecture on stage is the real one.

And every item it produces is tagged `source: DEMO_FALLBACK` all the way to the UI, with
`provenance: INCLUDES_SIMULATED_EVIDENCE` on the assurance score. Simulated evidence is
never presented as live network evidence — not in the API, not in the score, not on screen.

When the fallback stands in for a live call that failed, it also carries the reason the
live call failed. The simulated adapter has its own invented failure text, and on its own
it contradicts the logs: evidence reading "network timeout" for a run where the operator
answered HTTP 500 is worse than no reason at all, because it sends a reviewer looking in
the wrong place.

Failures are classified by what an operations team should do about them, not by status
code. `UNSUPPORTED` (400, 404, 422) means the network cannot answer for this device —
a provisioning matter that will not resolve by retrying. `SERVER` (5xx) is the operator's
problem and worth retrying. `RATE_LIMIT` means the evidence is obtainable, just not now.
`INTERNAL` means the fault is ours. All four end as `UNAVAILABLE` evidence, because none
of them is evidence against the claim — but the summary says which one happened.

## Endpoints

Confirmed against the Nokia developer portal for the MENA Ignite app:

```
POST https://network-as-code.p-eu.apihub.nokia.io
  /location-verification/v1/verify
  /location-retrieval/v0/retrieve
  /device-status/v0/connectivity
  /device-status/device-roaming-status/v1/retrieve
  /device-status/device-reachability-status/v1/retrieve
```

Two things about that host are worth noticing, because both cost time to discover. It is
an `apihub.nokia.io` host, not a `rapidapi.com` one — the key and the `x-rapidapi-host`
header belong to RapidAPI, but the endpoint is Nokia's. And there is no passthrough or
gateway prefix: the CAMARA path is the path.

Each capability carries its own CAMARA version and they do not move in step —
location-verification is on v1 while location-retrieval is still v0 — so the paths stay in
`NAC_PATH_*` environment variables rather than being compiled in. Roaming exists twice: the
older `device-status/v0/roaming` operation and the dedicated Device Roaming Status v1 API.
The v1 endpoint is the default.

Every request carries `x-correlator`, CAMARA's correlation header. The value the operator
echoes back is what lands on the evidence record, so a disputed item can be traced to a
single call in Nokia's logs. That is the difference between provenance and a timestamp.

If a path is ever wrong the system degrades correctly — `UNAVAILABLE` evidence and an
`UNVERIFIED` decision, never a wrong verdict — but you also get no live data.

## Simulator devices

The Nokia simulator answers for numbers in the `+99999991000` range and reports a fixed
position near Budapest. The seed data is calibrated against it:

**Each simulator number has its own position.** They are not one shared location, which
is the assumption to avoid: moving the sites around cannot make a given number verify.
The demo assigns numbers by the outcome each one was observed to produce.

| Work order | Number | Observed | Outcome |
|---|---|---|---|
| WO-1042 | `+99999991001` | inside Site A | `VERIFIED` |
| WO-1043 | `+99999991000` | outside every candidate site | `DISPUTED` |
| WO-1044 | `+99999990400` | HTTP 500 / 400 | `UNVERIFIED` |

Confirm the first two before a live demo — one call each, and it takes a minute:

```bash
for n in +99999991001 +99999991000; do
  curl -s -X POST https://network-as-code.p-eu.apihub.nokia.io/location-verification/v1/verify \
    -H "x-rapidapi-key: $NAC_RAPIDAPI_KEY" \
    -H "x-rapidapi-host: network-as-code.nokia.rapidapi.com" \
    -H "content-type: application/json" \
    -d "{\"device\":{\"phoneNumber\":\"$n\"},\"area\":{\"areaType\":\"CIRCLE\",\"center\":{\"latitude\":50.735851,\"longitude\":7.10066},\"radius\":1000}}"
done
```

Expect `TRUE` then `FALSE`. If the simulator has been reassigned, swap the two numbers in
`DemoOrganizationSeeder` and reseed.

WO-1043 is the interesting one: the conflict is produced by real geometry, not by a
hardcoded flag. The distance between the expected site and the observable position is what
makes the first signal come back conflicting — which is what makes the agent escalate.

Site B's coordinates are not arbitrary. The portal's own Location Verification example,
`DEVICE_INSIDE_AREA_FALSE_PHONE_NUMBER`, centres on 50.735851 / 7.10066 and returns
`FALSE` for `+99999991000` even at a 50 km radius. Seeding Site B there means the contested
scenario is the operator's documented negative case rather than something we arranged.
