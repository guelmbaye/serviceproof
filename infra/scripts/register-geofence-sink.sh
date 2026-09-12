#!/usr/bin/env bash
#
# Register a Geofencing subscription whose sink is ServiceProof itself.
#
# After this, the Network events screen has content and the 2:30 beat shows a
# CloudEvent inside the product rather than on a third-party page.
#
#   NAC_KEY=... WEBHOOK_TOKEN=... ./register-geofence-sink.sh
#
# The token is whatever you put in WEBHOOK_GEOFENCING_TOKEN on the droplet.
# It must already be set and the migration already run — an unset token makes
# the route 404 for Nokia exactly as it does for a stranger.

set -uo pipefail

BASE="https://network-as-code.p-eu.apihub.nokia.io"
GEO="$BASE/geofencing-subscriptions/v0.3/subscriptions"
HOST="network-as-code.nokia.rapidapi.com"
API="https://api.serviceproof.vylantic.com"

# The device documented as inside this area. initialEvent fires for it at once.
PHONE="+99999991001"
LAT=50.735851
LNG=7.10066
RADIUS=2000

# Both values already live in the deployment's .env. Asking for them on the
# command line means copying out what the server already knows, and getting
# one of them subtly wrong at the worst moment.
#
# Explicit variables still win when set, so this runs from a laptop too.
for candidate in infra/production/.env .env ../../.env; do
    [ -f "$candidate" ] || continue
    NAC_KEY="${NAC_KEY:-$(grep -m1 '^NAC_RAPIDAPI_KEY=' "$candidate" | cut -d= -f2-)}"
    WEBHOOK_TOKEN="${WEBHOOK_TOKEN:-$(grep -m1 '^WEBHOOK_GEOFENCING_TOKEN=' "$candidate" | cut -d= -f2-)}"
    [ -n "${NAC_KEY:-}" ] && [ -n "${WEBHOOK_TOKEN:-}" ] && echo "Using values from $candidate" && break
done

if [ -z "${NAC_KEY:-}" ]; then
    echo "No Nokia key. Set NAC_RAPIDAPI_KEY in .env, or pass NAC_KEY=..." >&2
    exit 1
fi

if [ -z "${WEBHOOK_TOKEN:-}" ]; then
    echo "No webhook token. Set WEBHOOK_GEOFENCING_TOKEN in .env (openssl rand -hex 24)," >&2
    echo "restart the api container so it is read, then run this again." >&2
    exit 1
fi

SINK="$API/api/v1/webhooks/geofencing/$WEBHOOK_TOKEN"

# ── 1. Is our own endpoint reachable and does it accept the token? ───────
#
# Prove this before involving Nokia. A subscription that delivers into a 404
# looks exactly like a simulator that does not emit.

echo "── Checking the sink ──"

probe=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$SINK" \
    -H 'content-type: application/json' \
    -d '{"type":"probe.ignored","data":{}}')

case "$probe" in
    204) echo "  $probe · reachable, token accepted, event type ignored as designed" ;;
    404) echo "  $probe · wrong token, or WEBHOOK_GEOFENCING_TOKEN is unset on the droplet"; exit 1 ;;
    *)   echo "  $probe · unexpected — check the vhost and that migrations have run"; exit 1 ;;
esac

# ── 2. Register the subscription ─────────────────────────────────────────

echo
echo "── Subscribing: area-entered on $PHONE ──"

curl -s -w '\n  HTTP %{http_code}\n' -X POST "$GEO" \
    -H "x-rapidapi-key: $NAC_KEY" \
    -H "x-rapidapi-host: $HOST" \
    -H "content-type: application/json" \
    -d "{
        \"protocol\": \"HTTP\",
        \"sink\": \"$SINK\",
        \"types\": [\"org.camaraproject.geofencing-subscriptions.v0.area-entered\"],
        \"config\": {
            \"subscriptionDetail\": {
                \"device\": { \"phoneNumber\": \"$PHONE\" },
                \"area\": {
                    \"areaType\": \"CIRCLE\",
                    \"center\": { \"latitude\": $LAT, \"longitude\": $LNG },
                    \"radius\": $RADIUS
                }
            },
            \"initialEvent\": true,
            \"subscriptionMaxEvents\": 10,
            \"subscriptionExpireTime\": \"2026-12-31T23:59:59.000Z\"
        }
    }" | sed "s|$WEBHOOK_TOKEN|<token>|g; s/^/  /"

# Nokia echoes the whole subscription back, sink URL included, and the sink URL
# is the token. Printing it verbatim puts the secret into every paste of this
# output — which is exactly how it reached a shared file the first time.

cat <<'EOF'

── Now ──

  Open https://serviceproof.vylantic.com/network-events

  An area-entered should be listed within seconds. If it is not, the
  subscription exists but nothing was delivered — read the POST response
  above for the subscription id, and remember a silent subscription and a
  rejected one look identical from here.

  One subscription is enough for the demo. A second would add a second
  identical row and no second argument.
EOF
