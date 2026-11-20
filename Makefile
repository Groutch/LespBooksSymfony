# Raccourcis de developpement. `make` seul affiche l'aide.
# Les commandes Symfony tournent sur l'hote ; `make shell` entre dans le conteneur.

DC := APP_UID=$(shell id -u) APP_GID=$(shell id -g) docker compose
# -u www-data : sinon les fichiers ecrits depuis le conteneur appartiennent a root sur l'hote.
EXEC := $(DC) exec -u www-data web

# Un shell lance depuis l'editeur peut exporter les valeurs du .env, dont un
# APP_SECRET vide : il primerait sur .env.dev et .env.test, et lint comme tests
# echoueraient sur « A non-empty secret is required ». Vide, il n'est pas transmis.
ifeq ($(strip $(APP_SECRET)),)
unexport APP_SECRET
endif

# Coordonnees de l'hebergement, non versionnees.
-include .deploy.local
DEPLOY_PATH ?= lesp
DEPLOY_PHP ?= php

.DEFAULT_GOAL := help
.PHONY: help up stop down restart logs shell cc assets test lint migrate deploy release backup ssh-key migrate-prod prod-logs prod-check prod-refresh

help: ## Affiche cette aide
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

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

deploy: ## Construit la branche deploy livree a OVH (make deploy PUSH=1 pour l'envoyer)
	./deploy/build.sh $(if $(PUSH),--push,)

ssh-key: ## Installe votre cle publique sur l'hebergement (mot de passe demande une fois)
	ssh-copy-id $(DEPLOY_SSH)

# Les scripts lisent eux-memes .deploy.local, dans la syntaxe de Make comme dans
# celle du shell (deploy/commun.sh).
release: ## Livraison complete : sauvegarde, pousse, attend OVH, vide le cache, migre (MIGRER=1 sans terminal)
	./deploy/release.sh

backup: ## Sauvegarde la base et les couvertures de production dans backups/
	./deploy/backup.sh

migrate-prod: ## Migrations sur l'hebergement (make migrate-prod RUN=1 pour appliquer)
	./deploy/migrate.sh $(if $(RUN),--run,)

prod-logs: ## Fin du journal d'erreurs de production le plus recent (N=lignes, 100 par defaut)
	./deploy/journaux.sh $(N)

prod-check: ## Controle le site en ligne : pages, feuille de style, aucun cookie, .env.local protege
	./deploy/controles.sh

prod-refresh: ## Apres un push manuel : vide le cache de production et applique les migrations
	./deploy/rafraichir.sh
