#!/usr/bin/env bash
set -Eeuo pipefail

# Safe SVP diagnostic helper.
# Default behavior is read-only. It never cancels a reservation, creates a hold,
# or attempts to clear an external SVP session.
#
# Required environment:
#   SVP_BASE_URL       e.g. https://svp-international-api.pacc.sa
#   SVP_BEARER_TOKEN   current scoped SVP bearer token
# Optional:
#   SVP_CSRF_TOKEN
#   SVP_TENANT_NAME    defaults to svp-international
#
# Examples:
#   SVP_BASE_URL=... SVP_BEARER_TOKEN=... ./scripts/svp-diagnostic.sh --user-id 1424648
#   ... ./scripts/svp-diagnostic.sh --reservation 5876290
#
# Deliberately unsupported:
#   --clear-external-holds
# SVP exposes no documented read/delete endpoint for temporary_seats. The
# temporary_seats endpoint is POST-only; a lingering labor hold must expire or
# be handled by SVP support/official account workflow. Do not guess a DELETE URL.

BASE_URL="${SVP_BASE_URL:-}"
TOKEN="${SVP_BEARER_TOKEN:-}"
CSRF="${SVP_CSRF_TOKEN:-}"
TENANT="${SVP_TENANT_NAME:-svp-international}"
USER_ID=""
RESERVATION_ID=""
CLEAR_LOCAL="false"

usage() {
  cat <<'EOF'
Usage: svp-diagnostic.sh [options]

Read-only options:
  --user-id ID                 Candidate/labor SVP user ID
  --reservation ID             Fetch one reservation detail
  --clear-local-session        Print a warning; does not clear anything in CLI mode
  --clear-external-holds       Refused: no supported SVP API exists
  -h, --help                   Show help
EOF
}

while (($#)); do
  case "$1" in
    --user-id) USER_ID="${2:?missing value}"; shift 2;;
    --reservation) RESERVATION_ID="${2:?missing value}"; shift 2;;
    --clear-local-session) CLEAR_LOCAL="true"; shift;;
    --clear-external-holds)
      echo "Refused: SVP has no documented read/delete temporary-seat-hold endpoint." >&2
      echo "Only POST /api/v1/individual_labor_space/temporary_seats is implemented." >&2
      exit 2
      ;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2;;
  esac
done

[[ -n "$BASE_URL" ]] || { echo "SVP_BASE_URL is required" >&2; exit 2; }
[[ -n "$TOKEN" ]] || { echo "SVP_BEARER_TOKEN is required; token value is never printed" >&2; exit 2; }
BASE_URL="${BASE_URL%/}"

headers=(-H 'Accept: application/json, text/plain, */*' -H "Authorization: Bearer ${TOKEN}" -H "X-Tenant-Name: ${TENANT}")
[[ -n "$CSRF" ]] && headers+=( -H "X-CSRF-Token: ${CSRF}" )

request() {
  local url="$1"
  local body status
  body="$(mktemp)"
  status="$(curl --silent --show-error --max-time 30 --dump-header "${body}.headers" --output "$body" --write-out '%{http_code}' "${headers[@]}" "$url")"
  echo "HTTP ${status} ${url}"
  echo "Response headers (rate-limit fields only):"
  grep -iE '^(HTTP/|retry-after:|x-ratelimit-|ratelimit-|rate-limit-)' "${body}.headers" || echo "  (none returned)"
  echo "Response body (credentials redacted):"
  sed -E 's/(Bearer[[:space:]]+)[^" ]+/\1[REDACTED]/Ig; s/(access_token|token|csrf|authorization)[" ]*:[" ]*[^",} ]+/\1:[REDACTED]/Ig' "$body" | head -c 20000
  echo
  rm -f "$body" "${body}.headers"
}

if [[ -n "$USER_ID" ]]; then
  request "${BASE_URL}/api/v1/individual_labor_space/exam_reservations"
  echo "Note: filter the returned records by labor/user ID ${USER_ID}; no temporary-hold list endpoint is available."
else
  request "${BASE_URL}/api/v1/individual_labor_space/exam_reservations"
fi

if [[ -n "$RESERVATION_ID" ]]; then
  request "${BASE_URL}/api/v1/individual_labor_space/exam_reservations/${RESERVATION_ID}?locale=en"
  echo "Cancellation is intentionally not attempted by this script."
fi

if [[ "$CLEAR_LOCAL" == "true" ]]; then
  echo "Local scoped application-session clearing requires the authenticated Laravel Request/session context."
  echo "Use the application logout/SVP re-login flow; this CLI script does not delete sessions."
fi

echo "Temporary hold status: SVP currently reports labor_id conflicts via POST /temporary_seats."
echo "There is no documented GET or DELETE endpoint for external temporary holds."
echo "Rate-limit guidance: honor Retry-After/X-RateLimit headers and wait at least 60 seconds after 429 before retrying."
