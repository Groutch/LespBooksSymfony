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

if [ -f .deploy.local ]; then
    # shellcheck disable=SC1091
    . ./.deploy.local
fi

: "${DEPLOY_SSH:?Renseignez DEPLOY_SSH dans .deploy.local}"
: "${DEPLOY_PATH:=lesp}"
: "${DEPLOY_PHP:=php}"

if [ "${1:-}" = "--run" ]; then
    echo "==> Application des migrations sur $DEPLOY_PATH"
    ssh "$DEPLOY_SSH" "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:migrate --no-interaction"
    ssh "$DEPLOY_SSH" "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console cache:clear"
else
    echo "==> Migrations en attente (aucune modification)"
    ssh "$DEPLOY_SSH" "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:status"
    echo
    echo "Pour appliquer :  ./deploy/migrate.sh --run"
fi
