#!/usr/bin/env bash
#
# Affiche la fin du journal d'erreurs de production le plus recent.
#
# En production, les erreurs s'ecrivent dans var/log/prod-AAAA-MM-JJ.log
# (config/packages/monolog.yaml), un fichier par jour garde 14 jours. Un jour
# sans erreur n'a pas de fichier : on lit donc le plus recent, quel qu'il soit.
#
#   ./deploy/journaux.sh [lignes]      (ou make prod-logs [N=lignes])
#
set -euo pipefail

cd "$(dirname "$0")/.."
. deploy/commun.sh

LIGNES=${1:-100}
if [[ ! $LIGNES =~ ^[0-9]+$ ]]; then
    echo "!! Nombre de lignes invalide : $LIGNES" >&2
    exit 1
fi

# prod-*.log ne prend pas prod.deprecations-*.log, ecrit a part. Un `cd` rate
# doit s'arreter : poursuivre afficherait « aucun journal », rassurant a tort.
distant "cd $DEPLOY_PATH || exit 1
    f=\$(ls -t var/log/prod-*.log 2>/dev/null | head -n 1)
    if [ -n \"\$f\" ]; then echo \"==> \$f\"; tail -n $LIGNES \"\$f\";
    else echo 'Aucun journal d erreurs : rien ne s est produit depuis 14 jours.'; fi"
