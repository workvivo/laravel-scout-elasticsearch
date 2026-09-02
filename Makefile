#!/usr/bin/make
# Makefile readme (en): <https://www.gnu.org/software/make/manual/html_node/index.html#SEC_Contents>

SHELL = /bin/sh
APP_CONTAINER_NAME := app
DOCKER_EXEC_USER ?=
DOCKER_EXEC_USER_FLAG := $(if $(DOCKER_EXEC_USER),--user $(DOCKER_EXEC_USER),)

docker_bin := $(shell command -v docker 2> /dev/null)
docker_compose_bin := $(docker_bin) compose

.PHONY : help test analyse test-coverage test-filter test-unit test-local \
         check-docker check-opensearch-host \
         up down restart shell install
.DEFAULT_GOAL := help

# This will output the help for each task. thanks to https://marmelab.com/blog/2016/02/29/auto-documented-makefile.html
help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# --- [ Development tasks ] -------------------------------------------------------------------------------------------

---------------: ## ---------------

up: check-docker check-opensearch-host ## Start all containers (in background) for development
	$(docker_compose_bin) up -d --build --remove-orphans

check-docker:
	@if [ -z "$(docker_bin)" ]; then \
		echo "Docker is required. Install Docker Desktop or make sure docker is on PATH."; \
		exit 1; \
	fi
	@if ! $(docker_compose_bin) version >/dev/null 2>&1; then \
		echo "Docker Compose v2 is required (docker compose). Install the Compose plugin or update Docker Desktop."; \
		exit 1; \
	fi

check-opensearch-host:
	@if [ "$$(uname -s)" = "Linux" ] && [ -r /proc/sys/vm/max_map_count ]; then \
		current=$$(cat /proc/sys/vm/max_map_count); \
		if [ "$$current" -lt 262144 ]; then \
			echo "vm.max_map_count must be at least 262144 for OpenSearch."; \
			echo "Run: sudo sysctl -w vm.max_map_count=262144"; \
			exit 1; \
		fi; \
	fi

down: check-docker ## Stop all started for development containers
	$(docker_compose_bin) down

restart: up ## Restart all started for development containers
	$(docker_compose_bin) restart

shell: up ## Start shell into application container
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" /bin/sh

install: up ## Install application dependencies into application container
	$(docker_compose_bin) exec $(DOCKER_EXEC_USER_FLAG) "$(APP_CONTAINER_NAME)" composer install --no-interaction --ansi

test: up ## Execute application tests
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off php -d memory_limit=512M ./vendor/bin/phpunit --testdox --stop-on-failure'

analyse: up ## Execute static analysis
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off ./vendor/bin/phpstan analyze --memory-limit=4000M'

test-coverage: up ## Execute application tests and generate report
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=coverage php -d memory_limit=512M ./vendor/bin/phpunit --coverage-html build/coverage-report'

test-filter:
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off php -d memory_limit=512M ./vendor/bin/phpunit --filter=$(filter) --testdox'

test-unit:
	$(docker_compose_bin) exec "$(APP_CONTAINER_NAME)" sh -lc 'XDEBUG_MODE=off php -d memory_limit=512M ./vendor/bin/phpunit $(filter-out $@,$(MAKECMDGOALS)) --testdox --stop-on-failure'

test-local: ## Run tests locally without docker (requires native mysql + opensearch + redis)
	XDEBUG_MODE=off php -d memory_limit=512M ./vendor/bin/phpunit --testdox
