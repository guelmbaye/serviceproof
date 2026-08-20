#!/usr/bin/env bash
# End-to-end smoke test: login -> claim -> verification -> decision.
set -euo pipefail

API="${API:-http://localhost:8000}"
AGENT="${AGENT:-http://localhost:9000}"
EMAIL="${EMAIL:-tech@acme-field.test}"
PASSWORD="${PASSWORD:-password}"

say() { printf '\n\033[36m▸ %s\033[0m\n' "$1"; }
jqq() { python3 -c "import sys,json;d=json.load(sys.stdin);print(eval('d$1'))"; }

say "Agent health"
curl -fsS "$AGENT/health" && echo

say "Login as $EMAIL"
TOKEN=$(curl -fsS -X POST "$API/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\",\"device_name\":\"smoke\"}" | jqq "['token']")
echo "token acquired"

AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json')

say "My work orders"
WO=$(curl -fsS "${AUTH[@]}" "$API/api/v1/work-orders?status=IN_PROGRESS" | jqq "['data'][0]['id']")
echo "work order: $WO"

say "Submit claim"
CLAIM=$(curl -fsS -X POST "${AUTH[@]}" "$API/api/v1/work-orders/$WO/claims" \
  -d '{"claim_type":"SERVICE_COMPLETED","claimed_at":"'"$(date -u +%Y-%m-%dT%H:%M:%SZ)"'","notes":"Smoke test claim","idempotency_key":"smoke-'"$RANDOM"'"}' \
  | jqq "['data']['id']")
echo "claim: $CLAIM"

say "Run verification"
curl -fsS -X POST "${AUTH[@]}" "$API/api/v1/claims/$CLAIM/verify" | python3 -m json.tool | head -40

say "Verification detail"
curl -fsS "${AUTH[@]}" "$API/api/v1/claims/$CLAIM" | python3 -m json.tool | head -60

printf '\n\033[32m✓ smoke test finished\033[0m\n'
