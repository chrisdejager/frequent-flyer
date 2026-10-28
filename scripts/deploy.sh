#!/usr/bin/env bash
# Deploys a commit to the VPS: the images CI built for it, plus the
# compose.prod.yaml of that same commit. See docs/deployment.md.
#
#   DEPLOY_HOST=<ssh host> scripts/deploy.sh            # latest origin/main
#   DEPLOY_HOST=<ssh host> scripts/deploy.sh <commit>   # also: rollback
#
# DEPLOY_DIR is the directory on the server (default: /var/apps/flights).
set -euo pipefail

cd "$(dirname "$0")/.."

: "${DEPLOY_HOST:?set DEPLOY_HOST to the ssh host of the VPS}"
dir=${DEPLOY_DIR:-/var/apps/flights}

git fetch -q origin main
sha=$(git rev-parse --verify "${1:-origin/main}^{commit}")

# CI pushes images only for commits on main.
if ! git merge-base --is-ancestor "$sha" origin/main; then
  echo "$sha is not on origin/main, so CI built no images for it." >&2
  exit 1
fi

echo "Deploying $(git log -1 --format='%h %s' "$sha") to $DEPLOY_HOST:$dir"

# The compose file goes next to the running one; the server script only
# puts it in place once the images are pulled.
git show "$sha:compose.prod.yaml" \
  | ssh "$DEPLOY_HOST" "mkdir -p '$dir' && cat > '$dir/compose.prod.yaml.new'"

ssh "$DEPLOY_HOST" "cd '$dir' && bash -s -- '$sha'" < scripts/deploy-on-server.sh
