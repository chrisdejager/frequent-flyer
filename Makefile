# The interface for local development: every tool runs in a container, so the
# host only needs Docker. `make help` lists the targets.

.DEFAULT_GOAL := help
.PHONY: help setup up down sh test phpunit test-offline web-test build smoke

# Run as the host user, so files the containers write stay yours (compose.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

PHP  := docker compose run --rm --no-deps php
NODE := docker compose run --rm --no-deps node

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-13s %s\n", $$1, $$2}'

# Safe to rerun: after a fresh clone, and after switching branches (new
# dependencies in composer.lock or package-lock.json). It never overwrites
# api/.env.local.
setup: ## Prepare for first use or after switching branches (safe to rerun)
	@test -f api/.env.local || { printf 'IGNAV_API_KEY=\n' > api/.env.local; \
		echo "Created api/.env.local"; }
	docker compose build php
	$(PHP) composer install --no-interaction
	$(NODE) npm install
	@grep -q '^IGNAV_API_KEY=..*' api/.env.local || \
		echo "Note: IGNAV_API_KEY in api/.env.local is empty, so searches answer 503 (README, Setup)."

up: ## Start the dev stack (app at http://localhost:8080)
	docker compose up

down: ## Stop the dev stack
	docker compose down

sh: ## Shell in the PHP container
	$(PHP) sh

test: phpunit web-test ## PHP suite and frontend suite

phpunit: ## PHP suite
	$(PHP) php bin/phpunit

# Proves that no test touches the network (AGENTS.md), like CI does.
test-offline: ## PHP suite without network access
	docker compose build php
	docker run --rm --network none --user "$(HOST_UID):$(HOST_GID)" \
		-v "$(CURDIR)/api:/app" -w /app flights-php:latest php bin/phpunit

web-test: ## Frontend suite
	$(NODE) npm run test

build: ## Frontend production build
	$(NODE) npm run build

smoke: ## Build the production images and smoke-test them without internet
	docker compose -f compose.prod.yaml build
	scripts/smoke-test.sh
