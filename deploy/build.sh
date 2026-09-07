#!/usr/bin/env bash
#
# Construit la livraison dans la branche `deploy`, que l'hebergement OVH clone tel quel.
# Rien n'est compile sur le serveur : ni Composer, ni Tailwind, ni AssetMapper.
#
#   ./deploy/build.sh          prepare la branche et s'arrete
#   ./deploy/build.sh --push   pousse egalement vers origin
#
set -euo pipefail

cd "$(dirname "$0")/.."

DIST=var/dist
TREE=var/deploy-tree
BRANCH=deploy
SOURCE_SHA=$(git rev-parse --short HEAD)

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Des modifications ne sont pas commitees : la livraison partira de $SOURCE_SHA." >&2
fi

echo "==> Export de HEAD ($SOURCE_SHA)"
rm -rf "$DIST"
mkdir -p "$DIST"
# git archive applique les export-ignore de .gitattributes : ni tests, ni Docker.
git archive HEAD | tar -x -C "$DIST"

echo "==> Dependances de production"
(cd "$DIST" && APP_ENV=prod composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

echo "==> CSS et assets"
mkdir -p "$DIST/var"
cp -r var/tailwind "$DIST/var/"
(cd "$DIST" && APP_ENV=prod php bin/console tailwind:build --minify --quiet)
(cd "$DIST" && APP_ENV=prod php bin/console asset-map:compile --quiet)

# var/ contient le cache du build, dont les chemins absolus sont ceux de cette machine.
rm -rf "$DIST/var"

echo "==> Preparation de la branche $BRANCH"
git worktree remove --force "$TREE" 2>/dev/null || true
rm -rf "$TREE"

if git show-ref --quiet "refs/heads/$BRANCH"; then
    git worktree add --quiet "$TREE" "$BRANCH"
else
    git worktree add --quiet --detach "$TREE"
    git -C "$TREE" checkout --quiet --orphan "$BRANCH"
    git -C "$TREE" rm -rqf . 2>/dev/null || true
fi

find "$TREE" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -a "$DIST/." "$TREE/"

git -C "$TREE" add -A
if git -C "$TREE" diff --cached --quiet; then
    echo "    rien de neuf a livrer"
else
    git -C "$TREE" commit --quiet -m "Livraison du $(date +%F) depuis $SOURCE_SHA"
    echo "    commit cree sur $BRANCH"
fi

if [ "${1:-}" = "--push" ]; then
    echo "==> Envoi vers origin/$BRANCH"
    git -C "$TREE" push --quiet origin "$BRANCH"
    echo "    OVH deploiera cette branche au prochain webhook"
else
    echo
    echo "Pret. Pour publier :  git -C $TREE push origin $BRANCH"
fi
