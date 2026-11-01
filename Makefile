# --- Makefile for MoonShine V ---
# Usage: make [target]

SHELL := bash
.ONESHELL:
.SHELLFLAGS := -eu -o pipefail -c
.DELETE_ON_ERROR:
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

# --- Project ---
PHP      ?= php
COMPOSER ?= composer
ARTISAN  := $(PHP) artisan
NPM      ?= npm

# ============================================================================
.DEFAULT_GOAL := help

##@ Development

.PHONY: install
install: ## Install all dependencies (PHP + Node)
	$(COMPOSER) install
	$(NPM) install

.PHONY: setup
setup: ## Full project setup (install, env, key, migrate, build)
	$(COMPOSER) setup

.PHONY: dev
dev: ## Start dev server (Laravel + Vite + queue + logs)
	$(COMPOSER) dev

.PHONY: serve
serve: ## Start Laravel dev server only
	$(ARTISAN) serve

.PHONY: tinker
tinker: ## Open interactive REPL
	$(ARTISAN) tinker

.PHONY: routes
routes: ## List application routes
	$(ARTISAN) route:list

##@ Testing

.PHONY: test
test: ## Run all tests
	$(ARTISAN) config:clear --ansi
	$(ARTISAN) test

.PHONY: test-module
test-module: ## Run API Admin module tests only (unit + integration)
	$(ARTISAN) config:clear --ansi
	./vendor/bin/phpunit tests/ApiAdmin/

.PHONY: test-module-unit
test-module-unit: ## Run API Admin unit tests only
	$(ARTISAN) config:clear --ansi
	./vendor/bin/phpunit tests/ApiAdmin/Unit/

.PHONY: test-module-integration
test-module-integration: ## Run API Admin integration tests only
	$(ARTISAN) config:clear --ansi
	./vendor/bin/phpunit tests/ApiAdmin/Integration/

.PHONY: test-cover
test-cover: ## Run tests with coverage report
	$(ARTISAN) config:clear --ansi
	./vendor/bin/phpunit --coverage-html coverage/ --coverage-text
	@echo "Coverage report: coverage/index.html"

.PHONY: test-filter
test-filter: ## Run filtered tests (usage: make test-filter FILTER="ClassName::testMethod")
	$(ARTISAN) config:clear --ansi
	./vendor/bin/phpunit --filter="$(FILTER)"

##@ Code Quality

.PHONY: lint
lint: ## Run Laravel Pint (dry-run)
	./vendor/bin/pint --test

.PHONY: lint-module
lint-module: ## Run Pint on the API Admin module only (dry-run)
	./vendor/bin/pint --test app/ApiAdmin/

.PHONY: stan
stan: ## Run PHPStan (level max via larastan)
	$(COMPOSER) stan

.PHONY: lint-fix
lint-fix: ## Fix code style with Laravel Pint
	./vendor/bin/pint

.PHONY: lint-fix-module
lint-fix-module: ## Fix code style in the API Admin module only
	./vendor/bin/pint app/ApiAdmin/

.PHONY: rector
rector: ## Run Rector (dry-run)
	./vendor/bin/rector process --dry-run

.PHONY: rector-fix
rector-fix: ## Apply Rector refactorings
	./vendor/bin/rector process

.PHONY: rector-module
rector-module: ## Run Rector on the API Admin module only (dry-run)
	./vendor/bin/rector process app/ApiAdmin/ --dry-run

.PHONY: rector-fix-module
rector-fix-module: ## Apply Rector refactorings in the API Admin module only
	./vendor/bin/rector process app/ApiAdmin/

.PHONY: fmt
fmt: lint-fix ## Alias for lint-fix

.PHONY: ai-validate
ai-validate: ## Full quality gate (pint + rector + audit + stan + tests)
	$(COMPOSER) ai:validate

.PHONY: install-hooks
install-hooks: ## Point git at .githooks/ (pre-commit = pint+stan, pre-push = ai:validate)
	git config core.hooksPath .githooks
	@echo "Hooks installed. Remove with: git config --unset core.hooksPath"

.PHONY: uninstall-hooks
uninstall-hooks: ## Disable repo hooks
	git config --unset core.hooksPath || true
	@echo "Hooks disabled."

.PHONY: check
check: lint test ## Run all quality checks

.PHONY: check-module
check-module: lint-module test-module ## Run all API Admin quality checks

##@ Database

.PHONY: db-migrate
db-migrate: ## Run database migrations
	$(ARTISAN) migrate

.PHONY: db-rollback
db-rollback: ## Rollback last migration
	$(ARTISAN) migrate:rollback

.PHONY: db-seed
db-seed: ## Seed the database
	$(ARTISAN) db:seed

.PHONY: db-fresh
db-fresh: ## Drop all tables and re-run all migrations + seeds
	$(ARTISAN) migrate:fresh --seed

##@ Cache & Optimization

.PHONY: cache-clear
cache-clear: ## Clear all caches
	$(ARTISAN) cache:clear
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear
	$(ARTISAN) view:clear

.PHONY: optimize
optimize: ## Cache views and keep live service/panel discovery enabled
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear
	$(ARTISAN) view:cache

.PHONY: reload
reload: ## Hot-reload: dump autoload + clear caches (after code generation)
	$(ARTISAN) api-admin:reload

##@ Api-Admin (app/ApiAdmin)


.PHONY: examples-up
examples-up: ## Start example API servers (blog :9101, shop :9102)
	bash examples/start-apis.sh

.PHONY: examples-down
examples-down: ## Stop example API servers
	bash examples/stop-apis.sh

.PHONY: examples-logs
examples-logs: ## Tail example API server logs
	tail -f examples/.logs/*.log

.PHONY: mcp-scenario
mcp-scenario: examples-up ## Run MCP smoke scenario (blog + shop services)
	$(PHP) examples/run-mcp-scenario.php

.PHONY: services-wipe
services-wipe: ## Remove generated ApiAdmin services and reset configs (keeps AdminHub)
	$(PHP) examples/wipe-services.php
	$(COMPOSER) dump-autoload -o -q
	$(ARTISAN) config:clear --ansi
	$(ARTISAN) moonshine:optimize-clear --ansi

.PHONY: services-regen
services-regen: services-wipe examples-up ## Wipe + regenerate services from examples/run-mcp-scenario.php
	$(PHP) examples/run-mcp-scenario.php
	$(COMPOSER) dump-autoload -o -q
	$(ARTISAN) api-admin:reload
	@echo "Regeneration complete."

.PHONY: docker-up
docker-up: ## Start development Docker containers
	docker compose --env-file docker/.env -f docker/docker-compose.yml up -d --build

.PHONY: docker-down
docker-down: ## Stop development Docker containers
	docker compose --env-file docker/.env -f docker/docker-compose.yml down

.PHONY: docker-logs
docker-logs: ## Tail development container logs
	docker compose --env-file docker/.env -f docker/docker-compose.yml logs -f

##@ Assets

.PHONY: build
build: ## Build frontend assets for production
	$(NPM) run build

.PHONY: vite
vite: ## Start Vite dev server
	$(NPM) run dev

##@ CI

.PHONY: ci
ci: install lint test build ## Run full CI pipeline

##@ Cleanup

.PHONY: clean
clean: ## Remove generated files and caches
	rm -rf vendor/ node_modules/ coverage/
	rm -rf bootstrap/cache/*.php storage/framework/cache/*
	rm -rf storage/framework/sessions/* storage/framework/views/*
	rm -rf public/build/

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage:\n  make \033[36m<target>\033[0m\n"} \
		/^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)}' $(MAKEFILE_LIST)
