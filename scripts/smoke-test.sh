#!/usr/bin/env bash
# Smoke test for the production images: starts compose.prod.yaml without
# internet access, checks it from the inside, and always tears it down again.
#
# Build first, the script never builds or pulls:
#   docker compose -f compose.prod.yaml build
#   scripts/smoke-test.sh
#
# TAG selects the images (default: latest), as in compose.prod.yaml.
set -euo pipefail

cd "$(dirname "$0")/.."

export APP_ENV_FILE=docker/smoke/app.env
compose=(docker compose -p flights-smoke -f compose.prod.yaml -f compose.smoke.yaml)
failures=0

cleanup() {
  "${compose[@]}" down --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
  echo "FAIL  $1"
  failures=$((failures + 1))
}

pass() {
  echo "ok    $1"
}

# GET a path through the nginx container. Prints the body, then the status
# code on the last line.
get() {
  "${compose[@]}" exec -T nginx curl -s -w '\n%{http_code}' "http://localhost$1"
}

# expect <path> <status> <text the body must contain>
expect() {
  local response status body
  response=$(get "$1")
  status=${response##*$'\n'}
  body=${response%$'\n'*}

  if [[ "$status" != "$2" ]]; then
    fail "GET $1: expected $2, got $status: ${body:0:200}"
  elif [[ "$body" != *"$3"* ]]; then
    fail "GET $1: body lacks '$3': ${body:0:200}"
  else
    pass "GET $1 → $2"
  fi
}

# in_php <description> <shell command>: runs inside the php container.
in_php() {
  if "${compose[@]}" exec -T php sh -c "$2" >/dev/null 2>&1; then
    pass "$1"
  else
    fail "$1"
  fi
}

"${compose[@]}" up -d --pull never >/dev/null 2>&1 || { "${compose[@]}" up -d --pull never; exit 1; }

for _ in $(seq 30); do
  [[ "$(get / 2>/dev/null | tail -n1)" == 200 ]] && break
  sleep 1
done

# Static: the Vue build is in the nginx image and served from the right root.
expect / 200 '<div id="app">'
asset=$(get / | grep -o '/assets/[^"]*\.js' | head -n1 || true)
if [[ -n "$asset" ]]; then
  expect "$asset" 200 ''
else
  fail "no /assets/*.js referenced in index.html"
fi

# API: nginx → php-fpm → Symfony in prod mode, both routes.
expect /api/search 400 'valid date'
expect '/api/booking-link?ref=x' 400 'Invalid or missing ref'

# A real search, with IGNAV_API_KEY empty: the env reaches the adapter through
# the prod container and the user gets the cause, not an empty list.
expect '/api/search?date=2030-01-15&direction=nl-fi&traveller=default' 503 'IGNAV_API_KEY'

# The php image itself.
in_php "container compiles with the prod config" 'php bin/console lint:container'
in_php "no dev dependencies in the image" '! test -d vendor/phpunit'
in_php "var/ is writable by php-fpm" 'su -s /bin/sh www-data -c "touch var/cache/prod/.smoke && rm var/cache/prod/.smoke"'

# The guarantee behind all of the above: nothing here can reach the internet.
in_php "no internet access from the stack" '! php -r "exit(@fsockopen(\"ignav.com\", 443, \$c, \$m, 3) ? 0 : 1);"'

if (( failures > 0 )); then
  echo "--- php logs"
  "${compose[@]}" logs --tail 30 php
  echo "$failures check(s) failed"
  exit 1
fi

echo "all checks passed"
