# ServiceProof Field

The technician's app. Mark a job complete; the network answers for you.

Flutter, three dependencies, no codegen.

---

## Setup

This repository ships `lib/`, `test/` and `pubspec.yaml` — the code that is actually ours.
The `android/` and `ios/` folders are generated, machine-specific, and not committed. Add
them once:

```bash
cd apps/mobile
flutter create --platforms=android,ios .   # adds only the missing platform folders
flutter pub get
```

`flutter create` on an existing directory fills in what is absent and leaves existing files
alone — which is the whole reason `test/widget_test.dart` is committed. Left free, that
path gets the counter-app template, which references a `MyApp` class this project has never
had, and `flutter analyze` fails on a repo you have not touched. The two files it will
still add, `.metadata` and `.gitignore`, are both harmless.

Then point it at the API and run:

```bash
# Android emulator — 10.2.2 is the host machine as seen from inside it
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1

# Physical handset on the same wifi — use your laptop's LAN address
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1

# iOS simulator
flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1
```

Sign in as `tech@acme-field.test` / `password`. That technician is assigned **WO-1042**
(verifies cleanly) and **WO-1044** (no device mapping, so it ends unverified).
`tech2@acme-field.test` holds **WO-1043**, the contested one.

```bash
flutter test        # 11 tests
flutter analyze
```

Requires Flutter 3.27 or newer (Dart 3.6). Older versions will fail on
`Color.withValues`.

---

## Assets

`assets/logo.png` and `assets/icon.png` are the shared brand files, and the theme's palette
is sampled from them so the handset and the ops console are visibly one product.

The launcher icon is not generated in this repo: `android/` and `ios/` are created per
machine by `flutter create`, so there is nothing committed to write it into. After
`make mobile-setup`, point `flutter_launcher_icons` at `assets/icon.png`.

## Three decisions worth defending

### This app never sends its own GPS

There is no `geolocator` dependency and no location permission in the manifest, and the
claim body contains no latitude or longitude — there is a test asserting exactly that.

This is the product's argument made structural. Self-reported location from the app of the
party being paid is precisely the evidence ServiceProof exists to replace. Shipping a
handset GPS reading alongside the claim would quietly reintroduce the thing we are
arguing against, and it would give an operations team a number that looks like proof and
is not.

The backend accepts `context.app_latitude` for clients that want it. This client declines.

### The technician is told what will be asked about them

Before submitting, and again on the submit screen, the app states plainly that their
operator will be asked two things about the work phone: whether it was inside the site
area, and whether it was connected. It also states the limits — a yes/no about an area
rather than a map, asked once for this job, with no name attached.

The technician is the data subject here. They should not learn what the system asks about
them by reading a press release.

This notice is the honest minimum, not the finished answer. Capturing a lawful basis
properly, per jurisdiction, is named as unbuilt work in
[`docs/05-security-and-trust.md`](../../docs/05-security-and-trust.md).

### The technician sees the outcome, not the evidence

The outcome screen shows `Verified` / `Partly verified` / `Needs review` / `Not verified`
and what happens next. It shows no evidence items, no request ids, no API names, no agent
trace.

That is not a simplification for small screens. The backend refuses to return network
evidence to a field worker — `ClaimController::show` loads it only for back-office roles.
A technician is entitled to the outcome of their own claim; they are not entitled to a
telecom record of their own movements rendered as a dossier, and neither is anyone who
picks up their phone. That detail belongs in the operations console.

---

## The offline path

A technician finishes a job in a basement, a lift shaft, or a rural substation. So the
claim is written to disk first and sent second.

```
Mark complete
   └─ PendingClaim.create()      idempotency key generated once, here
   └─ written to local storage   the technician's work is now safe
   └─ try to send
        ├─ 2xx  → done, outcome shown
        ├─ no signal → stays queued, banner appears, retried on resume
        ├─ 5xx  → stays queued, server is unwell not the claim
        └─ 4xx  → stays queued with the reason shown; retrying will not help
```

The idempotency key is generated once and reused on every retry. That is what makes the
offline path safe rather than merely convenient: the handset can try five times in a tunnel
and operations still sees exactly one claim, because Laravel's `ClaimService` returns the
original claim for a repeated key.

Two details that matter more than they look:

**`claimed_at` is captured on the handset, not on arrival.** An hour spent queued
underground must not move the time the work was actually finished — that timestamp is what
the network evidence gets compared against.

**Queue order is preserved.** When one claim cannot be sent, the ones behind it are not
tried out of order. That order is the technician's order of work.

The banner wording was chosen carefully. It says the work is *recorded*, not that sending
failed. A technician who believes their claim vanished will do the job again.

---

## Structure

```
lib/
├── main.dart               portrait lock, open the store, run
├── app.dart                wiring; flushes the outbox on resume
├── core/
│   ├── config.dart         --dart-define, no hosts in the repo
│   ├── api_client.dart     Offline is its own exception type, and that drives everything
│   └── store.dart          token + outbox persistence
├── models/models.dart      hand-written parsers against the API resources
├── data/
│   ├── pending_claim.dart  the queued claim and its idempotency key
│   └── repositories.dart   auth, work orders, claims
├── state/                  ChangeNotifier — no state-management package
└── ui/
    ├── theme.dart          the console's palette, sized for gloves and sunlight
    ├── widgets/
    └── screens/            sign in · jobs · job · submit · outcome
```

`Offline` being a distinct exception type is the load-bearing choice. Everything else —
the queue, the banner, the stale-list-beats-empty-list behaviour on the jobs screen —
follows from being able to tell "the server said no" apart from "we never reached the
server".

## Verified without a compiler

No Dart toolchain is reachable from the environment this was written in — the SDK binaries
live behind a blocked domain — so the app is checked structurally and by contract rather
than compiled. What that check does cover:

- Every endpoint the repositories call resolves to a declared Laravel route.
- Every JSON key the models read is emitted by the corresponding API resource.
- The claim submission body passes `StoreClaimRequest`'s rules field by field.
- Every member accessed on a model exists on that model, typed per file.
- Every widget constructor call supplies its required arguments.
- Balanced delimiters, resolving imports, no orphan types.

That is not a compiler and does not pretend to be. **Run `make mobile-setup && make
mobile-test` before demoing.** Two real defects were found this way and are described
below; a third class — anything only a type checker would catch — remains possible.

### Two defects the contract check found

**Clock skew lost claims.** `claimed_at` is stamped on the handset when the technician
taps, and the API validated it with `before_or_equal:now`. A phone whose clock ran one
second fast got a 422 — which the outbox cannot usefully retry, so the work was simply
gone. The rule now allows five minutes of skew, matching the tolerance the agent's
normaliser already uses on operator timestamps.

**A status that never existed.** `acceptsClaim` tested for `ASSIGNED`, which the backend
has never had. It was a dead branch rather than a visible failure, which is exactly why it
survived: the two real values carried the behaviour and the third did nothing. The check
is now the enum, and a test asserts that all eight backend statuses map to a defined
behaviour.

## Honest limitations

- **The token is in `SharedPreferences`, not the platform keystore.** On Android that is
  app-private storage, which is adequate for a demo and a managed fleet. A production
  build should move it to the keychain; `flutter_secure_storage` is the swap, and it
  touches one file.
- **No push notifications.** A technician learns their claim's outcome by opening the app.
  Operations verify within seconds, so the gap is small, but it is a gap.
- **No background sync.** The outbox flushes on app resume and on pull-to-refresh, not
  from a background isolate. A phone left in a pocket does not send.
- **No photo capture.** Deliberate for the demo — the whole pitch is that photos are the
  weak evidence being replaced — but real crews still need them for damage reports and
  parts, and that is a real gap rather than a principled one.
- **English only.** Given the target markets, Arabic and French with RTL support is not
  optional for a real deployment.
