#!/usr/bin/env bash
#
# Sauvegarde la base et les couvertures de production sur cette machine.
#
# L'hebergement ne garde rien pour nous : une migration ratee n'a pas de retour
# arriere sans dump, et une reinstallation Git du dossier efface public/uploads.
# `make release` appelle ce script avant chaque livraison.
#
# Les sauvegardes contiennent le nom des emprunteurs. Elles sont purgees apres
# app.backup_retention_days jours (config/services.yaml), duree annoncee sur
# /confidentialite. La purge a lieu a chaque sauvegarde : une copie trop vieille
# ne disparait donc qu'au passage suivant de ce script.
#
#   ./deploy/backup.sh       (ou make backup)
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

RETENTION=$(sed -nE 's/^[[:space:]]*app\.backup_retention_days:[[:space:]]*([0-9]+)[[:space:]]*$/\1/p' config/services.yaml)
if [ -z "$RETENTION" ]; then
    echo "!! app.backup_retention_days introuvable dans config/services.yaml : sans duree," >&2
    echo "   la purge promise sur /confidentialite ne peut pas avoir lieu. Sauvegarde annulee." >&2
    exit 1
fi

DOSSIER=${BACKUP_DIR:-backups}
HORODATAGE=$(date +%Y-%m-%d-%H%M%S)
BASE="$DOSSIER/base-$HORODATAGE.sql.gz"
COUVERTURES="$DOSSIER/couvertures-$HORODATAGE.tar.gz"

# Des noms d'emprunteurs : lisibles par ce seul compte.
umask 077
mkdir -p "$DOSSIER"

echo "==> Sauvegarde de la base"
# deploy/dump.php n'est pas livre : il part sur l'entree standard de PHP, cote
# serveur, et ne depend donc ni de la version en ligne ni de son cache (voir
# l'en-tete du script). mysqldump termine son export par « -- Dump completed » :
# son absence trahit un dump tronque (connexion coupee, droits insuffisants), que
# le code de retour transmis par SSH ne signale pas toujours.
if ! distant "cd $DEPLOY_PATH && $DEPLOY_PHP" < deploy/dump.php | gzip > "$BASE.partiel" \
    || ! gzip -dc "$BASE.partiel" | tail -n 1 | grep -q '^-- Dump completed'; then
    rm -f "$BASE.partiel"
    echo "!! Dump de la base incomplet : rien n'a ete conserve." >&2
    exit 1
fi
mv "$BASE.partiel" "$BASE"
echo "    $BASE ($(du -h "$BASE" | cut -f1))"

echo "==> Sauvegarde des couvertures"
# tar refuse de creer une archive vide : sans dossier uploads (aucune couverture
# encore), on archive explicitement une liste vide.
if ! distant "cd $DEPLOY_PATH/public && if [ -d uploads ]; then tar -czf - uploads; else tar -czf - --files-from /dev/null; fi" > "$COUVERTURES.partiel" \
    || ! tar -tzf "$COUVERTURES.partiel" > /dev/null; then
    rm -f "$COUVERTURES.partiel"
    echo "!! Archive des couvertures invalide : rien n'a ete conserve." >&2
    exit 1
fi
mv "$COUVERTURES.partiel" "$COUVERTURES"
echo "    $COUVERTURES ($(du -h "$COUVERTURES" | cut -f1), $(tar -tzf "$COUVERTURES" | grep -cv '/$') fichiers)"

echo "==> Purge des sauvegardes de plus de $RETENTION jours"
# -mtime +N ne retient que les fichiers d'au moins N+1 jours entiers : N-1, pour
# qu'aucune copie n'atteigne la duree annoncee.
find "$DOSSIER" -maxdepth 1 -type f \( -name 'base-*.sql.gz' -o -name 'couvertures-*.tar.gz' \) \
    -mtime +$((RETENTION - 1)) -print -delete | sed 's/^/    supprime /'
