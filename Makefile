SHELL := /bin/bash
COMPOSE := docker compose

.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS=":.*?## "}; {printf "\033[36m%-22s\033[0m %s\n", $$1, $$2}'

init: ## First-time setup (.env + build + install + migrate + seed)
	@test -f .env || cp .env.example .env
	$(COMPOSE) build
	$(COMPOSE) up -d postgres redis
	$(COMPOSE) run --rm api composer install --no-interaction --prefer-dist
	$(COMPOSE) run --rm api php artisan key:generate --force
	$(COMPOSE) up -d
	sleep 6
	$(MAKE) migrate
	$(MAKE) seed

up: ## Start the full stack
	$(COMPOSE) up -d

down: ## Stop the stack
	$(COMPOSE) down

logs: ## Tail all logs
	$(COMPOSE) logs -f --tail=100

migrate: ## Run Laravel migrations
	$(COMPOSE) exec -T api php artisan migrate --force

# config:clear before every seed, deliberately.
#
# The verification policies are built from config('serviceproof.policy_templates'),
# so a cached config silently overrides the file. Changing a policy's evidence
# budget and reseeding then appears to do nothing: the seeder's own code takes
# effect, the config-derived values do not, and the two disagree with no error
# anywhere. Clearing costs a second and removes the whole class of confusion.
fresh: ## Drop + rebuild the database, then seed
	$(COMPOSE) exec -T api php artisan config:clear
	$(COMPOSE) exec -T api php artisan migrate:fresh --seed --force

# Invoked through bash rather than executed directly.
#
# The executable bit does not reliably survive the trip to a server: git's
# core.fileMode can be off, a Windows checkout drops it, and a manual copy
# never had it. `make demo-reset` failing with "Permission denied" after a
# clean deploy is a pointless way to lose ten minutes.
geofence-sink: ## Register a Geofencing subscription pointing at this deployment
	bash infra/scripts/register-geofence-sink.sh

# Reseed, then re-subscribe. Both halves, because one without the other leaves
# the Network events screen empty.
#
# migrate:fresh drops every table including network_events, and Nokia's
# initialEvent only fires when a subscription is created — an existing one
# stays silent. So a reseed always costs a new subscription, and forgetting
# that means discovering an empty screen at 2:30 of a recording.
demo-reset: ## Reseed AND re-register the geofence sink — use this before recording
	$(MAKE) fresh
	bash infra/scripts/register-geofence-sink.sh

seed: ## Seed demo organisations, users, work orders
	$(COMPOSE) exec -T api php artisan config:clear
	$(COMPOSE) exec -T api php artisan db:seed --force

test: ## Run Laravel + agent test suites
	$(COMPOSE) exec -T api php artisan test
	$(COMPOSE) exec -T agent pytest -q

typecheck: ## Typecheck the operations console
	$(COMPOSE) exec -T web npx tsc --noEmit

mobile-setup: ## Generate the Flutter platform folders and fetch packages
	cd apps/mobile && flutter create --platforms=android,ios . && flutter pub get

mobile-test: ## Run the field app test suite
	cd apps/mobile && flutter test

mobile-run: ## Run the field app against the local stack (Android emulator)
	cd apps/mobile && flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1

smoke: ## End-to-end smoke test against the running stack
	bash infra/scripts/smoke.sh

web-shell: ## Shell into the Next.js console container
	$(COMPOSE) exec web sh

agent-shell: ## Shell into the FastAPI agent container
	$(COMPOSE) exec agent bash

api-shell: ## Shell into the Laravel container
	$(COMPOSE) exec api bash

psql: ## Open a psql session
	$(COMPOSE) exec postgres psql -U $${DB_USERNAME:-serviceproof} -d $${DB_DATABASE:-serviceproof}

.PHONY: help init up down logs migrate fresh seed test typecheck mobile-setup mobile-test mobile-run smoke web-shell agent-shell api-shell psql
