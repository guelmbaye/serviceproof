#!/usr/bin/env bash
#
# Which capabilities does this key actually reach?
#
# A 403 and a 429 from the same key on two endpoints mean different things,
# and RapidAPI says which in the response body — which the agent discards,
# because an operations console has no business showing a reviewer a gateway's
# billing message.
#
#   bash infra/scripts/check-nac-key.sh
#
# Reads NAC_RAPIDAPI_KEY from the deployment .env, like the other scripts.

set -uo pipefail

BASE="https://network-as-code.p-eu.apihub.nokia.io"
HOST="network-as-code.nokia.rapidapi.com"
PHONE="+99999991001"

for candidate in infra/production/.env .env ../../.env; do
    [ -f "$candidate" ] || continue
    NAC_KEY="${NAC_KEY:-$(grep -m1 '^NAC_RAPIDAPI_KEY=' "$candidate" | cut -d= -f2-)}"
    [ -n "${NAC_KEY:-}" ] && echo "Using the key from $candidate" && break
done

if [ -z "${NAC_KEY:-}" ]; then
    echo "No key. Set NAC_RAPIDAPI_KEY in .env, or pass NAC_KEY=..." >&2
    exit 1
fi

echo "Key ends ...${NAC_KEY: -6}"
echo

probe () {
    local label="$1" path="$2" body="$3"

    local out code payload
    out=$(curl -s -m 15 -w '\n__CODE__%{http_code}' -X POST "$BASE$path" \
        -H "x-rapidapi-key: $NAC_KEY" \
        -H "x-rapidapi-host: $HOST" \
        -H "content-type: application/json" \
        -d "$body")

    code="${out##*__CODE__}"
    payload="${out%$'\n'__CODE__*}"

    printf '%-24s %s\n' "$label" "$code"

    case "$code" in
        200|201)
            echo "                         reachable · $(echo "$payload" | head -c 70)" ;;
        403)
            echo "                         this app is NOT subscribed to this API,"
            echo "                         or the subscription lapsed. Subscribe on RapidAPI."
            echo "                         gateway said: $(echo "$payload" | head -c 90)" ;;
        429)
            echo "                         subscribed, but the quota is spent. The key works;"
            echo "                         the plan does not have calls left today."
            echo "                         gateway said: $(echo "$payload" | head -c 90)" ;;
        401)
            echo "                         the key itself is wrong or revoked." ;;
        404)
            echo "                         wrong path — not a key problem." ;;
        *)
            echo "                         $(echo "$payload" | head -c 90)" ;;
    esac
    echo
}

probe "Location Verification" "/location-verification/v1/verify" \
    "{\"device\":{\"phoneNumber\":\"$PHONE\"},\"area\":{\"areaType\":\"CIRCLE\",\"center\":{\"latitude\":50.735851,\"longitude\":7.10066},\"radius\":1000}}"

probe "Device Swap" "/passthrough/camara/v1/device-swap/device-swap/v1/check" \
    "{\"phoneNumber\":\"$PHONE\",\"maxAge\":240}"

probe "Device Status" "/device-status/v0/connectivity" \
    "{\"device\":{\"phoneNumber\":\"$PHONE\"}}"

cat <<'EOF'
── Reading this ──

  All 200          The key is fine. If the console still shows DEMO_FALLBACK,
                   the container is holding an older key: check
                   `spc exec agent printenv NAC_RAPIDAPI_KEY` and restart it.

  403 on some      Subscribe those APIs to this application on RapidAPI.
                   Regenerating a key keeps its subscriptions; creating a new
                   application starts with none, which is the usual cause.

  429 on some      The key works and the plan is spent. Wait for the window to
                   reset, or raise the plan. Nothing to fix in the code.

  Mixed 403/429    Both of the above at once — which is what a new application
                   plus a day of testing looks like.

Whatever the answer, the product behaved correctly: every substituted item is
tagged simulated, the score says "includes simulated evidence", and no verdict
claims a live measurement it did not make. That is the fallback doing its job,
not a bug.
EOF
