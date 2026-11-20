# shellcheck shell=bash
#
# Socle des scripts de deploy/ qui parlent a l'hebergement, charge par
# `. deploy/commun.sh` : il ne s'execute pas seul. Il fournit les coordonnees
# (DEPLOY_SSH, DEPLOY_PATH, DEPLOY_PHP, DEPLOY_URL, BACKUP_DIR) et `distant`,
# qui fait passer toutes les commandes par une seule connexion SSH.

# --- Coordonnees ----------------------------------------------------------------
#
# .deploy.local est lu par Make (-include) ET par ces scripts. Make accepte
# « CLE = valeur » ou « CLE := valeur », qu'un `source` du shell rejette : un
# fichier ecrit a la mode Make laissait les scripts sans coordonnees, sans le
# moindre message. Le fichier est donc lu ligne a ligne, sans etre execute, dans
# les deux syntaxes. Une valeur deja presente dans l'environnement prime.
lire_coordonnees() {
    local fichier=.deploy.local ligne cle valeur
    [ -f "$fichier" ] || return 0

    while IFS= read -r ligne || [ -n "$ligne" ]; do
        [[ $ligne =~ ^[[:space:]]*((DEPLOY|BACKUP)_[A-Z0-9_]+)[[:space:]]*[:?]*=[[:space:]]*(.*)$ ]] || continue
        cle=${BASH_REMATCH[1]}
        valeur=${BASH_REMATCH[3]}
        valeur=${valeur%"${valeur##*[![:space:]]}"}
        if [[ $valeur =~ ^\"(.*)\"$ || $valeur =~ ^\'(.*)\'$ ]]; then
            valeur=${BASH_REMATCH[1]}
        fi
        if [ -z "${!cle:-}" ]; then
            printf -v "$cle" '%s' "$valeur"
            export "${cle?}"
        fi
    done < "$fichier"
}

lire_coordonnees
: "${DEPLOY_SSH:?Renseignez DEPLOY_SSH dans .deploy.local (voir README, Déploiement)}"
export DEPLOY_PATH="${DEPLOY_PATH:-lesp}"
export DEPLOY_PHP="${DEPLOY_PHP:-php}"

# --- Connexion SSH unique ---------------------------------------------------------
#
# La boucle d'attente de release.sh interroge le serveur toutes les 5 secondes :
# avec une authentification par mot de passe, chaque commande le redemanderait.
# Une connexion maitresse porte l'authentification, les suivantes la reutilisent.
# Un script lance par un autre (backup.sh par release.sh) herite de LESP_SSH_CTL
# et reprend la connexion de son parent, a qui revient de la fermer.
if [ -z "${LESP_SSH_CTL:-}" ]; then
    LESP_SSH_CTL="$(mktemp -u "${TMPDIR:-/tmp}/lesp-ssh-XXXXXXXX")"
    export LESP_SSH_CTL
    trap 'ssh -O exit -o ControlPath="$LESP_SSH_CTL" "$DEPLOY_SSH" 2>/dev/null || true' EXIT
fi

# ControlPersist couvre le build, pendant lequel aucune commande ne passe.
distant() {
    ssh -o ControlMaster=auto -o ControlPath="$LESP_SSH_CTL" -o ControlPersist=300 "$DEPLOY_SSH" "$@"
}
