#!/usr/bin/env bash
#
# Livre, attend qu'OVH ait repris la branche, puis vide le cache de production.
#
# Le webhook OVH est asynchrone et n'execute aucune commande. Entre le push et
# la replication, le serveur sert les nouveaux assets avec les anciens gabarits
# encore compiles dans var/cache/prod : la page sort a moitie habillee, ce qui
# ressemble a un bug de CSS alors que c'est un bug de sequence. Le vidage du
# cache etait jusqu'ici une seconde etape manuelle, donc oubliable.
#
# Coordonnees lues dans .deploy.local (non versionne, le depot est public) :
#   DEPLOY_SSH=login@sshXX.clusterXXX.hosting.ovh.net
#   DEPLOY_PATH=lesp
#   DEPLOY_PHP=/usr/local/php8.4/bin/php
#   DEPLOY_URL=https://lesp.groutch.com   (facultatif : active les controles finaux)
#
#   ./deploy/release.sh          construit, pousse, attend, vide le cache
#
set -euo pipefail

cd "$(dirname "$0")/.."

# Make transmet deja les coordonnees (cible `release`). La lecture directe ne
# sert qu'a l'appel manuel du script.
if [ -z "${DEPLOY_SSH:-}" ] && [ -f .deploy.local ]; then
    # Le fichier est lu a la fois par make et par les scripts. Make tolere
    # « CLE = valeur » avec des espaces autour du signe egal, `source` non :
    # un fichier ecrit a la mode Make laisse les scripts sans coordonnees,
    # sans le moindre message. On normalise donc avant de lire.
    eval "$(sed -E \
        -e 's/[[:space:]]+$//' \
        -e 's/^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*[:?+]?=[[:space:]]*(.*)$/\1="\2"/' \
        .deploy.local | grep -E '^[A-Za-z_][A-Za-z0-9_]*=')"
fi

: "${DEPLOY_SSH:?Renseignez DEPLOY_SSH dans .deploy.local}"
: "${DEPLOY_PATH:=lesp}"
: "${DEPLOY_PHP:=php}"

# OVH replique en quelques secondes d'ordinaire ; la marge couvre les jours creux.
ATTENTE_MAX="${ATTENTE_MAX:-180}"
INTERVALLE=5

./deploy/build.sh --push

# refs/heads/deploy et non `deploy` : le depot contient aussi un dossier deploy/,
# et Git considere le nom comme ambigu (`git log deploy` echoue franchement).
ATTENDU=$(git rev-parse refs/heads/deploy)
echo
echo "==> Attente de la replication par OVH (livraison ${ATTENDU:0:7})"

DEBUT=$(date +%s)
while :; do
    DISTANT=$(ssh "$DEPLOY_SSH" "cd $DEPLOY_PATH && git rev-parse HEAD" 2>/dev/null || true)

    if [ "$DISTANT" = "$ATTENDU" ]; then
        echo "    OVH est a jour"
        break
    fi

    ECOULE=$(( $(date +%s) - DEBUT ))
    if [ "$ECOULE" -ge "$ATTENTE_MAX" ]; then
        echo "!! OVH n'a pas repris la livraison apres ${ATTENTE_MAX}s." >&2
        echo "   Attendu sur le serveur : ${ATTENDU:0:7}" >&2
        echo "   Trouve                 : ${DISTANT:0:7}" >&2
        echo "   Le cache n'a PAS ete vide. Relancez plus tard :  make prod-refresh" >&2
        exit 1
    fi

    echo "    pas encore (${ECOULE}s)"
    sleep "$INTERVALLE"
done

echo
echo "==> Vidage du cache de production"
ssh "$DEPLOY_SSH" "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console cache:clear"

# Controles finaux, seulement si l'URL publique est renseignee.
if [ -n "${DEPLOY_URL:-}" ]; then
    echo
    echo "==> Controles"

    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$DEPLOY_URL/" || echo 000)
    echo "    page d'accueil : $CODE"
    [ "$CODE" = "200" ] || { echo "!! Le site ne repond pas correctement." >&2; exit 1; }

    # Le piege qui a deja coute une fuite : docroot pointant sur lesp et non
    # lesp/public, rendant .env.local telechargeable.
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$DEPLOY_URL/.env.local" || echo 000)
    echo "    .env.local     : $CODE"
    if [ "$CODE" = "200" ]; then
        echo "!! .env.local est SERVI PUBLIQUEMENT. Le dossier racine du domaine doit" >&2
        echo "   pointer sur $DEPLOY_PATH/public, pas sur $DEPLOY_PATH." >&2
        exit 1
    fi
fi

echo
echo "Livraison terminee."
