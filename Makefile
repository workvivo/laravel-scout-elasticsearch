#!/usr/bin/make
# Makefile readme (en): <https://www.gnu.org/software/make/manual/html_node/index.html#SEC_Contents>

SHELL = /bin/sh
APP_CONTAINER_NAME := app

docker_bin := $(shell command -v docker 2> /dev/null)
docker_compose_bin := $(docker_bin) compose

.PHONY : help test analyse check-opensearch-host \
         up down restart shell install
.DEFAULT_GOAL := help

# This will output the help for each task. thanks to https://marmelab.com/blog/2016/02/29/auto-documented-makefile.html
help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- [ Development tasks ] -------------------------------------------------------------------------------------------

---------------: ## ---------------

up: check-opensearch-host ## Start all containers (in background) for development
	$(docker_compose_bin) up -d

check-opensearch-host:
	@if [ "$$(uname -s)" = "Linux" ] && [ -r /proc/sys/vm/max_map_count ]; then \
		current=$$(cat /proc/sys/vm/max_map_count); \
		if [ "$$current" -lt 262144 ]; then \
			echo "vm.max_map_count must be at least 262144 for OpenSearch."; \
			echo "Run: sudo sysctl -w vm.max_map_count=262144"; \
			exit 1; \
		fi; \
	fi

down: ## Stop all started for development containers
	$(docker_compose_bin) down

restart: up ## Restart all started for development containers
	$(docker_compose_bin) restart

shell: up ## Start shell into application container
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" /bin/sh

install: up ## Install application dependencies into application container
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" composer install --no-interaction --ansi

test: up ## Execute application tests
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off ./vendor/bin/phpunit --testdox --stop-on-failure'

analyse: up ## Execute static analysis
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" ./vendor/bin/phpstan analyze --memory-limit=4000M

test-coverage: up ## Execute application tests and generate report
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html build/coverage-report'

test-filter:
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off ./vendor/bin/phpunit --filter=$(filter) --testdox'

test-unit:
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off ./vendor/bin/phpunit $(filter-out $@,$(MAKECMDGOALS)) --testdox --stop-on-failure'

test-local: ## Run tests locally without docker (requires native mysql + opensearch)
	XDEBUG_MODE=off ./vendor/bin/phpunit --testdox
