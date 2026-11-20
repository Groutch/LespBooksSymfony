#!/usr/bin/env bash
#
# Applique les migrations sur l'hebergement, par SSH.
# Le webhook OVH ne fait que repliquer les fichiers : la base reste a notre charge.
#
# Coordonnees lues dans .deploy.local (non versionne, le depot est public) :
#   DEPLOY_SSH=login@sshXX.clusterXXX.hosting.ovh.net
#   DEPLOY_PATH=lesp
#   DEPLOY_PHP=/usr/local/php8.4/bin/php
#
#   ./deploy/migrate.sh          affiche les migrations en attente
#   ./deploy/migrate.sh --run    les applique
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

if [ "${1:-}" = "--run" ]; then
    echo "==> Application des migrations sur $DEPLOY_PATH"
    distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:migrate --no-interaction"
    distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console cache:clear"
else
    echo "==> Migrations en attente (aucune modification)"
    distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:status"
    echo
    echo "Pour appliquer :  make migrate-prod RUN=1"
fi
