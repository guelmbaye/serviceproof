#!/usr/bin/env bash
#
# Everything that must be true before you press record.
#
#   bash infra/scripts/pre-flight.sh
#
# Six checks, each of which has already cost a take. It says GO, or it names
# the one thing to fix. No dependencies beyond curl.

set -uo pipefail

API="${API:-https://api.serviceproof.vylantic.com/api/v1}"
AGENT="${AGENT:-https://ai.serviceproof.vylantic.com}"
CONSOLE="${CONSOLE:-https://serviceproof.vylantic.com}"

EMAIL="ops@acme-field.test"
PASSWORD="password"

PASS=0
FAIL=0

ok ()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad ()  { printf '  \033[31m✗\033[0m %s\n' "$1"; printf '      %s\n' "$2"; FAIL=$((FAIL+1)); }

# A tiny JSON field reader. Not a parser — enough for flat keys in known
# responses, and it keeps this script dependency-free on a fresh droplet.
field () { sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^,\"}]*\).*/\1/p" <<< "$2" | head -1; }

echo
echo "── 1 · The agent is up and holds a key ──"

health=$(curl -s -m 10 "$AGENT/health")

if [ -z "$health" ]; then
    bad "The agent did not answer" "Check: spc logs agent"
else
    live=$(field "live_credentials" "$health")
    finger=$(field "key_fingerprint" "$health")

    if [ "$live" = "true" ] && [ -n "$finger" ]; then
        ok "Live credentials present · key $finger"
        echo "      Compare with .env:  grep NAC_RAPIDAPI_KEY infra/production/.env"
        echo "      If they differ:     spc up -d --force-recreate agent"
    elif [ "$live" = "true" ]; then
        # The field exists in the source but not in this running image. Saying
        # "key " with nothing after it is worse than saying why.
        ok "Live credentials present"
        echo "      This container predates the key fingerprint, so it cannot be"
        echo "      compared from outside. git pull does not rebuild an image:"
        echo "      spc up -d --build agent"
    else
        bad "No Nokia key in the container" \
            "Every item will be tagged simulated. spc up -d --force-recreate agent"
    fi
fi

echo
echo "── 2 · Signing in ──"

login=$(curl -s -m 10 -X POST "$API/auth/login" \
    -H 'content-type: application/json' \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"pre-flight\"}")

TOKEN=$(field "token" "$login")

if [ -z "$TOKEN" ]; then
    bad "Could not sign in as $EMAIL" "Has the database been seeded? make demo-reset"
    echo
    echo "Stopping: the remaining checks need a session."
    exit 1
fi
ok "Signed in"

auth () { curl -s -m 10 -H "authorization: Bearer $TOKEN" "$@"; }

echo
echo "── 3 · Both demonstration claims are unverified ──"

claims=$(auth "$API/claims")

for ref in CLM-1042 CLM-1043; do
    # The status sits next to the reference in the list payload.
    chunk=$(grep -o "\"reference\":\"$ref\"[^}]*}[^}]*" <<< "$claims" | head -1)

    if grep -q '"status":"SUBMITTED"' <<< "$chunk"; then
        ok "$ref is unverified — it will run live on camera"
    elif [ -z "$chunk" ]; then
        bad "$ref not found" "make demo-reset"
    else
        bad "$ref has already been verified" \
            "Both runs must happen on camera. make demo-reset"
    fi
done

echo
echo "── 4 · Network events has something to show ──"

# Nokia delivers within seconds, not instantly, and this check usually runs
# moments after a subscription was created. Looking once is a race written to
# be lost — so wait, briefly, and say so rather than appearing to hang.
count=0
for attempt in 1 2 3 4 5 6; do
    events=$(auth "$API/network-events")
    count=$(grep -o '"event_type"' <<< "$events" | wc -l | tr -d ' ')

    [ "$count" -gt 0 ] && break

    if [ "$attempt" -eq 1 ]; then
        printf '  … nothing yet; giving Nokia up to 30s to deliver'
    else
        printf '.'
    fi
    sleep 5
done

[ "$count" -eq 0 ] && echo

if [ "$count" -gt 0 ]; then
    ok "$count event(s) received"
else
    bad "Nothing received after 30s" \
        "The 2:30 beat would show an empty screen. Re-subscribe:
      bash infra/scripts/register-geofence-sink.sh
      then check the sink is reachable from outside, not just from the droplet."
fi

echo
echo "── 5 · The console answers ──"

code=$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$CONSOLE/login")
[ "$code" = "200" ] && ok "Console reachable" || bad "Console returned $code" "spc logs web"

echo
echo "────────────────────────────────────────────"

if [ "$FAIL" -eq 0 ]; then
    cat <<'EOF'

  GO.

  Two things this script cannot check, and both have cost a take:

    · Clear the browser of bookmarks, extra tabs and notifications.
    · Open the two claim windows side by side for the 1:45 beat, before
      you start recording. Building that arrangement on camera reads as
      fumbling.

  Script: docs/07-demo-video-script.md

EOF
    exit 0
fi

printf '\n  %d check(s) failed. Fix those first.\n\n' "$FAIL"
exit 1
