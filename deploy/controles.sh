#!/usr/bin/env bash
#
# Controle le site en ligne, de l'exterieur, comme un visiteur : rien que des
# requetes GET sur DEPLOY_URL (.deploy.local).
#
# Appele par release.sh en fin de livraison, et a tout moment par
# `make prod-check`. Tous les controles passent avant le verdict : une panne en
# cache souvent une autre.
#
#   ./deploy/controles.sh        (ou make prod-check)
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

: "${DEPLOY_URL:?Renseignez DEPLOY_URL dans .deploy.local pour controler le site}"
DEPLOY_URL=${DEPLOY_URL%/}

PROBLEMES=()
statut() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$1" || echo 000; }
ligne() { printf '    %-34s %s\n' "$1" "$2"; }

echo "==> Controles de $DEPLOY_URL"

# Le catalogue en plus de l'accueil : il interroge davantage la base.
for PAGE in / /catalogue; do
    CODE=$(statut "$DEPLOY_URL$PAGE")
    ligne "$PAGE" "$CODE"
    [ "$CODE" = 200 ] || PROBLEMES+=("$PAGE repond $CODE au lieu de 200")
done

ACCUEIL=$(curl -s -D - --max-time 20 "$DEPLOY_URL/" || true)

# La feuille de style compilee : une page peut repondre 200 et sortir nue.
CSS=$(grep -oE '/assets/styles/app-[^"]+\.css' <<< "$ACCUEIL" | head -n 1 || true)
if [ -z "$CSS" ]; then
    ligne "feuille de style" "introuvable"
    PROBLEMES+=("l'accueil ne reference aucune feuille de style compilee")
else
    CODE=$(statut "$DEPLOY_URL$CSS")
    ligne "feuille de style" "$CODE"
    [ "$CODE" = 200 ] || PROBLEMES+=("$CSS repond $CODE au lieu de 200")
fi

# Promesse de /confidentialite, qui dispense le site de bandeau de consentement.
if grep -qi '^set-cookie:' <<< "$ACCUEIL"; then
    ligne "cookie depose sur l'accueil" "OUI"
    PROBLEMES+=("l'accueil depose un cookie, alors que /confidentialite affirme le contraire")
else
    ligne "cookie depose sur l'accueil" "aucun"
fi

# Le piege qui a deja coute une fuite : dossier racine du domaine sur le projet
# et non sur public/, rendant les secrets telechargeables.
CODE=$(statut "$DEPLOY_URL/.env.local")
ligne "/.env.local (doit etre refuse)" "$CODE"
if [ "$CODE" = 200 ]; then
    PROBLEMES+=(".env.local est SERVI PUBLIQUEMENT : le dossier racine du domaine doit pointer sur $DEPLOY_PATH/public")
fi

if [ ${#PROBLEMES[@]} -gt 0 ]; then
    echo "!! Controles en echec :" >&2
    printf '   - %s\n' "${PROBLEMES[@]}" >&2
    echo "   Erreurs du serveur : make prod-logs" >&2
    exit 1
fi
echo "    tout est en ordre"
