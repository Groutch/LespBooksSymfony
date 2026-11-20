#!/usr/bin/env bash
#
# Livre, attend qu'OVH ait repris la branche, vide le cache de production, puis
# applique les migrations que la livraison apporte.
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
#   BACKUP_DIR=backups                    (facultatif : dossier des sauvegardes)
#
#   ./deploy/release.sh          sauvegarde, construit, pousse, attend, vide le cache, migre
#   MIGRER=1 ./deploy/release.sh confirme d'avance les migrations (sans terminal)
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

# OVH replique en quelques secondes d'ordinaire ; la marge couvre les jours creux.
ATTENTE_MAX="${ATTENTE_MAX:-180}"
INTERVALLE=5

# Premiere connexion : elle porte l'authentification, les suivantes la reutilisent.
echo "==> Connexion a l'hebergement"
distant true

# --- Migrations, annoncees avant tout envoi --------------------------------------
#
# Une migration qui AJOUTE une colonne casse le site tant qu'elle n'est pas
# appliquee : le nouveau code la reclame des sa mise en ligne. Appliquees a la
# main apres la livraison, elles laissaient le site en erreur 500 le temps de
# taper la commande. Elles sont donc annoncees ici — un refus n'envoie rien —
# puis appliquees des la replication.
echo
echo "==> Migrations"

# Celles que la livraison apporte : presentes dans HEAD, que build.sh livre,
# absentes du serveur.
version() { sed -n 's#^\(migrations/\)\{0,1\}\(Version[0-9]*\)\.php$#\2#p' | sort; }
APPORTEES=$(comm -23 \
    <(git ls-tree --name-only HEAD migrations/ | version) \
    <(distant "ls $DEPLOY_PATH/migrations" | version))

# Et celles deja sur le serveur mais jamais appliquees (une livraison passee par
# `make deploy PUSH=1`) : elles partiront avec les autres, autant le dire.
ETAT=$(distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:up-to-date --no-interaction" 2>&1 || true)
case "$ETAT" in
    *Up-to-date!*) EN_ATTENTE= ;;
    *Out-of-date!*) EN_ATTENTE=oui ;;
    *)
        echo "!! Etat des migrations illisible sur le serveur : rien n'a ete envoye." >&2
        echo "$ETAT" | sed 's/^/   /' >&2
        exit 1
        ;;
esac

if [ -z "$APPORTEES" ] && [ -z "$EN_ATTENTE" ]; then
    echo "    aucune"
else
    for VERSION in $APPORTEES; do
        # La description de la migration, a defaut le nom de sa classe.
        DESCRIPTION=$(git show "HEAD:migrations/$VERSION.php" | sed -n "s/^[[:space:]]*return '\(.*\)';[[:space:]]*$/\1/p" | head -n 1)
        echo "    $VERSION  ${DESCRIPTION:-(sans description)}"
    done
    if [ -n "$EN_ATTENTE" ]; then
        echo "    + des migrations deja sur le serveur, jamais appliquees."
    fi
    echo "    Elles seront appliquees juste apres la mise en ligne, la sauvegarde faite."

    if [ "${MIGRER:-}" = 1 ]; then
        echo "    MIGRER=1 : confirmation donnee d'avance."
    elif [ -t 0 ]; then
        read -r -p "Continuer ? [o/N] " REPONSE
        if [[ ! $REPONSE =~ ^[oOyY] ]]; then
            echo "Livraison abandonnee : rien n'a ete envoye."
            exit 1
        fi
    else
        echo "!! Aucun terminal pour confirmer : relancez avec  make release MIGRER=1" >&2
        exit 1
    fi
fi

# Avant de toucher au serveur : c'est cet etat qu'on voudra retrouver si la
# livraison tourne mal. Une sauvegarde ratee arrete la livraison.
echo
./deploy/backup.sh
echo

./deploy/build.sh --push

# refs/heads/deploy et non `deploy` : le depot contient aussi un dossier deploy/,
# et Git considere le nom comme ambigu (`git log deploy` echoue franchement).
ATTENDU=$(git rev-parse refs/heads/deploy)
echo
echo "==> Attente de la replication par OVH (livraison ${ATTENDU:0:7})"

DEBUT=$(date +%s)
while :; do
    DISTANT=$(distant "cd $DEPLOY_PATH && git rev-parse HEAD" 2>/dev/null || true)

    if [ "$DISTANT" = "$ATTENDU" ]; then
        echo "    OVH est a jour"
        break
    fi

    ECOULE=$(( $(date +%s) - DEBUT ))
    if [ "$ECOULE" -ge "$ATTENTE_MAX" ]; then
        echo "!! OVH n'a pas repris la livraison apres ${ATTENTE_MAX}s." >&2
        echo "   Attendu sur le serveur : ${ATTENDU:0:7}" >&2
        echo "   Trouve                 : ${DISTANT:0:7}" >&2
        echo "   Ni cache vide, ni migrations appliquees. Relancez plus tard :" >&2
        echo "   make prod-refresh, puis make migrate-prod RUN=1" >&2
        exit 1
    fi

    echo "    pas encore (${ECOULE}s)"
    sleep "$INTERVALLE"
done

echo
echo "==> Vidage du cache de production"
distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console cache:clear"

# Apres le vidage : la migration tourne ainsi sur un code et un conteneur
# coherents. Sans migration en attente, la commande ne fait rien.
echo
echo "==> Migrations"
if ! distant "cd $DEPLOY_PATH && $DEPLOY_PHP bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"; then
    echo "!! Une migration a echoue : le site est peut-etre en erreur." >&2
    echo "   Sauvegarde d'avant livraison : $(ls -t "${BACKUP_DIR:-backups}"/base-*.sql.gz 2>/dev/null | head -n 1)" >&2
    echo "   Pour restaurer : README, section Sauvegardes." >&2
    exit 1
fi

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
