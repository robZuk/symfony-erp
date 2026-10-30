# The Symfony CLI wrappers inject DATABASE_URL from the running Docker services.
# CI overrides them: make test CONSOLE="php bin/console" PHP=php
CONSOLE ?= symfony console
PHP ?= symfony php

.DEFAULT_GOAL := help
.PHONY: help up down serve db migrate demo css test lint fix stan deptrac

help: ## Show available targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

up: ## Start Postgres, Redis, RabbitMQ, Elasticsearch and Mailpit
	docker compose up -d --wait

down: ## Stop containers
	docker compose down

serve: up ## Start the local web server (also watches Tailwind, see .symfony.local.yaml)
	symfony server:start -d

db: ## Create the dev database and run migrations
	$(CONSOLE) doctrine:database:create --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

demo: ## Replace everything in the dev database with demo data, and rebuild the search index from it
	$(CONSOLE) doctrine:fixtures:load --no-interaction --purge-with-truncate
	$(CONSOLE) app:search:reindex

css: ## Build the Tailwind stylesheet
	$(CONSOLE) tailwind:build

test: css ## Prepare the test database and run PHPUnit
	$(CONSOLE) --env=test doctrine:database:create --if-not-exists
	$(CONSOLE) --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PHP) bin/phpunit

lint: stan deptrac ## Run all static checks
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(CONSOLE) lint:container
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:yaml config

fix: ## Fix coding style
	$(PHP) vendor/bin/php-cs-fixer fix

stan: ## Static analysis
	$(CONSOLE) cache:warmup
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

deptrac: ## Check module boundaries
	$(PHP) vendor/bin/deptrac analyse
