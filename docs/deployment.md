# Deployment

Production is two Docker images that CI builds, smoke-tests and pushes to
ghcr.io for every commit on `main` (ADR-0007). A deploy pulls the images of
one commit onto the VPS and restarts the stack. The host nginx is the reverse
proxy: TLS with the wildcard certificate for `chrisdejager.nl`, and basic
auth for the whole site.

```
internet ──443──▶ host nginx (TLS, basic auth)
                    └─ flights.chrisdejager.nl → 127.0.0.1:8081
                                                   └─ [nginx container] → [php-fpm container]
```

## Deploy

```bash
DEPLOY_HOST=<ssh host> scripts/deploy.sh            # latest origin/main
DEPLOY_HOST=<ssh host> scripts/deploy.sh <commit>   # a specific commit
```

The commit must be on `origin/main` and its CI run must be green (otherwise
its images don't exist). The script:

1. uploads `compose.prod.yaml` of that commit as `compose.prod.yaml.new`;
2. pulls the images tagged with the commit sha. **If that fails, nothing has
   changed**;
3. puts the new compose file in place, `up -d`, and records the sha as `TAG`
   in `.env` on the server;
4. checks `/` (200) and `/api/search` without parameters (400) on
   `127.0.0.1:8081`. It never calls ignav.

It prints the previous sha. **Rollback** is a deploy of that sha:
`scripts/deploy.sh <previous sha>`.

`DEPLOY_DIR` (default `/var/apps/flights`) is the directory on the server.

## One-time server setup

The ssh user must be able to run `docker` (member of the `docker` group) and
write to `/var/apps/flights`.

1. **DNS.** `flights.chrisdejager.nl` must resolve to the VPS. Not needed if
   there is a wildcard record `*.chrisdejager.nl`.

2. **Read access to the private images.** On GitHub: Settings → Developer
   settings → Personal access tokens (classic) → new token with **only**
   `read:packages`. On the VPS, as the ssh user:

   ```bash
   docker login ghcr.io -u chrisdejager      # paste the token as password
   ```

3. **Secrets.**

   ```bash
   mkdir -p /var/apps/flights
   # create /var/apps/flights/app.env from app.env.example, with the real values
   chmod 600 /var/apps/flights/app.env
   ```

4. **Basic auth** (closes I7: `/api` spends ignav credits).

   ```bash
   sudo apt install apache2-utils            # for htpasswd
   sudo mkdir -p /etc/nginx/htpasswd
   sudo htpasswd -c /etc/nginx/htpasswd/flights <user>
   ```

5. **Host nginx**: `/etc/nginx/sites-available/flights.chrisdejager.nl`, then
   `sudo ln -s ../sites-available/flights.chrisdejager.nl /etc/nginx/sites-enabled/`,
   `sudo nginx -t && sudo systemctl reload nginx`. Copy the certificate paths
   and TLS settings from an existing `*.chrisdejager.nl` site.

   ```nginx
   server {
       listen 443 ssl;
       http2 on;
       server_name flights.chrisdejager.nl;

       ssl_certificate     /etc/letsencrypt/live/chrisdejager.nl/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/chrisdejager.nl/privkey.pem;

       auth_basic           "flights";
       auth_basic_user_file /etc/nginx/htpasswd/flights;

       location / {
           proxy_pass         http://127.0.0.1:8081;
           proxy_set_header   Host              $host;
           proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
           proxy_set_header   X-Forwarded-Proto https;
       }
   }

   server {
       listen 80;
       server_name flights.chrisdejager.nl;
       return 301 https://$host$request_uri;
   }
   ```

   Booking links can take ~20 s (measured once); the default
   `proxy_read_timeout` of 60 s covers that.

## On the server

In `/var/apps/flights` (`.env` holds the deployed `TAG`, so these stay on it):

```bash
docker compose -f compose.prod.yaml ps
docker compose -f compose.prod.yaml logs -f php        # app logs (stderr)
docker compose -f compose.prod.yaml exec php php bin/console cache:pool:clear cache.app
```

After changing `app.env`: `docker compose -f compose.prod.yaml up -d`
(containers are recreated with the new environment).

A wrong or missing `IGNAV_API_KEY` shows in the app as "Flight search is not
configured: …", not as an empty list.

## Before a deploy, locally

```bash
docker compose -f compose.prod.yaml build
scripts/smoke-test.sh
```

CI runs the same smoke test; this is for trying a change to the images
before pushing.
