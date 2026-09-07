# Raccourcis de developpement. `make` seul affiche l'aide.
# Les commandes Symfony tournent sur l'hote ; `make shell` entre dans le conteneur.

DC := APP_UID=$(shell id -u) APP_GID=$(shell id -g) docker compose
# -u www-data : sinon les fichiers ecrits depuis le conteneur appartiennent a root sur l'hote.
EXEC := $(DC) exec -u www-data web

.DEFAULT_GOAL := help
.PHONY: help up stop down restart logs shell cc assets test lint migrate

help: ## Affiche cette aide
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-8s\033[0m %s\n", $$1, $$2}'

up: ## Demarre la pile sur http://127.0.0.1:8000
	$(DC) up -d --build --force-recreate
	@echo "Site    http://127.0.0.1:$${APP_PORT:-8000}"
	@echo "Mailpit http://127.0.0.1:$$($(DC) port mailer 8025 | cut -d: -f2)"

stop: ## Arrete les conteneurs sans les supprimer
	$(DC) stop

down: ## Arrete et supprime les conteneurs (la base est conservee)
	$(DC) down

restart: stop up ## Redemarre la pile

logs: ## Suit les journaux des conteneurs
	$(DC) logs -f

shell: ## Ouvre un shell dans le conteneur web
	$(EXEC) bash

cc: ## Vide le cache de l'hote et celui du conteneur
	php bin/console cache:clear
	-@$(EXEC) php bin/console cache:clear

assets: ## Recompile Tailwind puis vide les caches
	php bin/console tailwind:build
	@$(MAKE) --no-print-directory cc

test: ## Lance la suite de tests
	php bin/phpunit

lint: ## Verifie gabarits, conteneur et schema
	php bin/console lint:twig templates
	php bin/console lint:container
	php bin/console doctrine:schema:validate

migrate: ## Applique les migrations sur app et app_test
	php bin/console doctrine:migrations:migrate --no-interaction
	php bin/console doctrine:migrations:migrate --no-interaction --env=test
