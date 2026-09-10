#!/usr/bin/env bash
#
# Geofencing probe — does the Nokia simulator emit geofence events?
#
# This touches nothing in ServiceProof. There is no geofencing code in the
# build, deliberately: this decides whether any gets written.
#
# Run it from anywhere:
#   NAC_KEY=... SINK=https://webhook.site/your-id ./geofencing-probe.sh
#
# Then watch your webhook.site tab. Total: about ten minutes of work and an
# hour of waiting.

set -uo pipefail

BASE="https://network-as-code.p-eu.apihub.nokia.io"
GEO="$BASE/geofencing-subscriptions/v0.3/subscriptions"
HOST="network-as-code.nokia.rapidapi.com"

# The area both control devices are measured against.
LAT=50.735851
LNG=7.10066
RADIUS=2000

INSIDE="+99999991001"    # Nokia: "device is in the given area"
OUTSIDE="+99999991000"   # Nokia: "device is not in the given area"

bold() { printf '\n\033[1m%s\033[0m\n' "$1"; }
fail() { printf '\033[31m%s\033[0m\n' "$1"; }
ok()   { printf '\033[32m%s\033[0m\n' "$1"; }

# ── Preconditions, stated rather than assumed ────────────────────────────

if [ -z "${NAC_KEY:-}" ]; then
    fail "NAC_KEY is not set."
    echo "  Use a freshly rotated key — the ones in NOKIA.txt have been shared."
    exit 1
fi

if [ -z "${SINK:-}" ]; then
    fail "SINK is not set."
    echo "  Open webhook.site, copy your unique URL, and pass it as SINK."
    echo "  The subscription needs a publicly reachable HTTPS endpoint."
    exit 1
fi

case "$SINK" in
    https://*) ;;
    *) fail "SINK must be https://. Nokia will not push to plain HTTP."; exit 1 ;;
esac

call() {
    curl -s -w '\n__HTTP__%{http_code}' \
        -H "x-rapidapi-key: $NAC_KEY" \
        -H "x-rapidapi-host: $HOST" \
        -H "content-type: application/json" \
        "$@"
}

show() {
    local body="${1%$'\n'__HTTP__*}"
    local code="${1##*__HTTP__}"
    echo "  HTTP $code"
    echo "$body" | (python3 -m json.tool 2>/dev/null || cat) | sed 's/^/  /'
    LAST_CODE="$code"
    LAST_BODY="$body"
}

# ── Step 1 · re-confirm the baseline ─────────────────────────────────────
#
# If Location Verification no longer answers as documented, nothing below
# means anything. Thirty seconds to rule that out.

bold "1 · Location Verification baseline"

for phone in "$INSIDE" "$OUTSIDE"; do
    echo
    echo "  $phone"
    result=$(call -X POST "$BASE/location-verification/v1/verify" \
        -d "{\"device\":{\"phoneNumber\":\"$phone\"},\"area\":{\"areaType\":\"CIRCLE\",\"center\":{\"latitude\":$LAT,\"longitude\":$LNG},\"radius\":1000}}")
    show "$result"
done

echo
echo "  Expected: TRUE for $INSIDE, FALSE for $OUTSIDE."
echo "  Anything else and the two control devices have been reassigned."

# ── Step 2 · area-entered, one subscription per device ───────────────────
#
# One event type per subscription: Nokia rejects an array with both.

subscribe() {
    local phone="$1" type="$2" initial="$3"
    call -X POST "$GEO" -d "{
        \"protocol\": \"HTTP\",
        \"sink\": \"$SINK\",
        \"types\": [\"org.camaraproject.geofencing-subscriptions.v0.$type\"],
        \"config\": {
            \"subscriptionDetail\": {
                \"device\": { \"phoneNumber\": \"$phone\" },
                \"area\": {
                    \"areaType\": \"CIRCLE\",
                    \"center\": { \"latitude\": $LAT, \"longitude\": $LNG },
                    \"radius\": $RADIUS
                }
            },
            \"initialEvent\": $initial,
            \"subscriptionMaxEvents\": 10,
            \"subscriptionExpireTime\": \"2026-12-31T23:59:59.000Z\"
        }
    }"
}

bold "2 · area-entered  ·  initialEvent: true"

declare -a IDS=()

for phone in "$INSIDE" "$OUTSIDE"; do
    echo
    echo "  $phone"
    show "$(subscribe "$phone" "area-entered" "true")"

    id=$(echo "$LAST_BODY" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("id",""))' 2>/dev/null)
    [ -n "$id" ] && IDS+=("$id")
done

# ── Step 3 · read the subscriptions back ─────────────────────────────────
#
# This is what separates "never created" from "created and silent". On
# webhook.site those two look identical and mean opposite things.

bold "3 · Reading the subscriptions back"

if [ ${#IDS[@]} -eq 0 ]; then
    fail "  No subscription id was returned. Read the HTTP codes above:"
    echo "    404 → wrong path. Check the current version on the portal."
    echo "    4xx → body shape rejected."
    echo "    2xx with no id → the response shape differs from expectations."
    echo
    echo "  Either way this is not an answer about the simulator. Stop here."
    exit 1
fi

for id in "${IDS[@]}"; do
    echo
    echo "  $id"
    show "$(call "$GEO/$id")"
done

# ── Step 4 · area-left, on its own ───────────────────────────────────────
#
# initialEvent is false on purpose: we want a genuine later transition, not
# an initial event whose semantics would muddy the reading.

bold "4 · area-left  ·  initialEvent: false  ·  $INSIDE"
echo
show "$(subscribe "$INSIDE" "area-left" "false")"

# ── What to do now ───────────────────────────────────────────────────────

bold "Now watch webhook.site"

cat <<'EOF'

  Within seconds
    an area-entered for +99999991001, and nothing for +99999991000.
    That proves the whole chain: subscription accepted, simulator evaluated
    the geofence, notification delivered, CloudEvent received. Far stronger
    than a 201.

  Within the hour
    an area-left for +99999991001 — or nothing.

  Reading the result

    ENTER and a real LEAVE
      V3 geofencing is validated. Keep it as the hero capability and build
      the dwell pipeline.

    ENTER only
      The simulator gives no demonstrable movement. An hour of silence does
      not prove it never will, but it is more than enough to refuse to build
      a deadline demo on undocumented behaviour. Keep the current build:
      device A supports, device B conflicts, same policy, different agent
      behaviour. It already works and it is already recorded.

    Nothing, but the subscriptions read back as live
      Either the sink is unreachable from Nokia or the simulator does not
      evaluate. Test your sink first: curl -X POST "$SINK" -d 'hello'
      should appear on webhook.site immediately.

  One hour. Not a day. Whatever the answer, it decides the mechanism of
  proof, not whether ServiceProof works.

EOF
