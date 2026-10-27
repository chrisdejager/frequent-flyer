#!/usr/bin/env bash
# Runs on the VPS, in the deploy directory, started by scripts/deploy.sh:
#   bash deploy-on-server.sh <sha>
# Expects compose.prod.yaml.new (uploaded) and app.env (one-time setup).
set -euo pipefail

tag=$1
compose_new=(docker compose -f compose.prod.yaml.new)
compose=(docker compose -f compose.prod.yaml)

if [[ ! -f app.env ]]; then
  echo "No app.env in $PWD. One-time setup: see docs/deployment.md." >&2
  exit 1
fi

# .env holds the deployed TAG, so a plain `docker compose -f compose.prod.yaml
# up/logs/ps` on the server stays on the deployed images.
previous=$(sed -n 's/^TAG=//p' .env 2>/dev/null || true)

# Pull first: if the images don't exist (CI not finished, no ghcr login),
# nothing has changed yet.
if ! TAG=$tag "${compose_new[@]}" pull; then
  rm -f compose.prod.yaml.new
  echo "Pull failed; the running version is untouched." >&2
  echo "Is CI finished for $tag, and is this server logged in to ghcr.io?" >&2
  exit 1
fi

mv compose.prod.yaml.new compose.prod.yaml
TAG=$tag "${compose[@]}" up -d --remove-orphans
{ grep -v "^TAG=" .env 2>/dev/null || true; echo "TAG=$tag"; } > .env.tmp
mv .env.tmp .env

# Health: the static app, and an API route that reaches Symfony without
# calling ignav (400 for missing parameters).
port=$(sed -n "s/^HTTP_PORT=//p" .env)
port=${port:-8081}
healthy=false
for _ in $(seq 30); do
  if [[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/")" == 200 \
     && "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/api/search")" == 400 ]]; then
    healthy=true
    break
  fi
  sleep 1
done

if [[ $healthy != true ]]; then
  echo "Deployed $tag, but the health check failed. Logs:" >&2
  "${compose[@]}" logs --tail 30 >&2
  [[ -n $previous ]] && echo "Roll back with: scripts/deploy.sh $previous" >&2
  exit 1
fi

echo "Deployed $tag, healthy."
[[ -n $previous && $previous != "$tag" ]] && echo "Previous: $previous (roll back with: scripts/deploy.sh $previous)"
exit 0
