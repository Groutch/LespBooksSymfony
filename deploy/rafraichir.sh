#!/usr/bin/env bash
#
# Remet le serveur d'aplomb une fois la livraison repliquee par OVH : vide le
# cache de production, puis applique les migrations en attente.
#
# Appele par release.sh des la replication, et par `make prod-refresh` apres un
# push manuel (make deploy PUSH=1) ou une replication trop lente pour release.sh.
#
# Le cache d'abord : sans lui, le serveur sert les nouveaux assets avec les
# anciens gabarits compiles (page a moitie habillee), et la migration tournerait
# sur un conteneur qui ne correspond plus au code.
#
#   ./deploy/rafraichir.sh       (ou make prod-refresh)
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

echo "==> En ligne : $(distant "cd $DEPLOY_PATH && git log -1 --format='%h %s'")"

echo
echo "==> Vidage du cache de production"
distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console cache:clear"

# Sans migration en attente, la commande ne fait rien.
echo
echo "==> Migrations"
if ! distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"; then
    echo "!! Une migration a echoue : le site est peut-etre en erreur." >&2
    echo "   Derniere sauvegarde : $(ls -t "${BACKUP_DIR:-backups}"/base-*.sql.gz 2>/dev/null | head -n 1)" >&2
    echo "   Pour restaurer : README, section Sauvegardes. Erreur detaillee : make prod-logs" >&2
    exit 1
fi
