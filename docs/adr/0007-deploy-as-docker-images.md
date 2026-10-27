# ADR-0007: Deploy as Docker images, not with Deployer

**Status:** Accepted
**Date:** 2026-10-27
**Deciders:** owner

## Context
The app goes to the owner's Ubuntu VPS, which already runs Docker and a host
nginx serving several `*.chrisdejager.nl` subdomains with a wildcard
certificate. In other projects the owner deploys with Deployer: SSH in, clone
into `releases/N/`, `composer install`, link `shared/` files, switch the
`current` symlink, reload php-fpm.

Development and the test suites already run in Docker (`php:8.4-fpm-alpine`).
Every serious bug in this codebase sat in a seam between components, never
inside one (see `AGENTS.md`).

## Decision
1. **Two images with the code baked in.** `flights-php` (target `prod` in
   `docker/php/Dockerfile`: no dev dependencies, warmed prod cache, opcache
   without timestamp checks) and `flights-nginx` (`docker/nginx/Dockerfile`:
   the built Vue app plus `prod.conf`). Secrets are not in the images; they
   come from `app.env` on the server.
2. **On the VPS only `compose.prod.yaml` and `app.env`.** The nginx container
   listens on `127.0.0.1:8081`. The host nginx is the reverse proxy for
   `flights.chrisdejager.nl`: TLS with the existing wildcard certificate, and
   basic auth on the whole site (closes I7: `/api` spends ignav credits).
3. **Built and tested in GitHub Actions, private on ghcr.io.** Unit tests,
   build, a smoke test against the running prod stack, and only then push,
   tagged with the commit sha. The image that was tested is the image that
   is deployed.
4. **Deploy is triggered by hand** (`workflow_dispatch` or `make deploy`):
   `pull` + `up -d` with the sha tag. Rollback is the same with the previous
   sha.

## Alternatives considered
- **Deployer.** Zero-downtime symlink switch and a familiar `dep rollback`.
  Rejected: it adds a second PHP runtime (the host's PHP 8.4, extensions,
  php-fpm, composer) that drifts independently of the one dev and tests use,
  needs Node or an upload step for the Vue build, and with
  `opcache.validate_timestamps=0` a forgotten fpm reload silently keeps old
  code running. Zero downtime is irrelevant for two users.
- **Deployer only as the SSH trigger around `pull && up -d`.** Possible later;
  for three commands it is a dependency we don't need.
- **Build on the VPS** (`git pull && docker compose build`). No registry, but
  needs Node and RAM on the server and deploys an image no pipeline tested.

## Consequences
- A few seconds of downtime per deploy while containers are recreated.
- The VPS needs a one-time `docker login ghcr.io` with a `read:packages` token.
- `cache.app` (booking links, train price) lives in the container and is empty
  after every deploy. Acceptable: both caches are short-lived.
