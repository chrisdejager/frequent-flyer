# Production web image: the built Vue app plus the nginx config that sends
# /api to php-fpm. Build from the repo root:
#   docker build -f docker/nginx/Dockerfile .

FROM node:22-alpine AS build
WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

FROM nginx:alpine

LABEL org.opencontainers.image.source=https://github.com/chrisdejager/frequent-flyer
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=build /web/dist /srv/web
