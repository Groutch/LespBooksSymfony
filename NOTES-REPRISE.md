# Notes de reprise

Ce fichier permet de reprendre le projet à froid, dans n'importe quelle session
ou n'importe quel espace de travail. À lire en premier.

## Le projet en deux lignes

Bibliothèque communautaire de Lespouey. Deux parties : un back-office mobile
pour scanner et cataloguer les livres, et un site vitrine public de consultation.
Remplace une ancienne application React Native / Appwrite (`../LespBooks`).

## Stack et raisons

| Choix | Pourquoi |
|---|---|
| Symfony 7.4 LTS, PHP 8.4 | Support long ; l'hébergement OVH propose 8.5 mais on vise 8.4 pour pouvoir déménager |
| MySQL | Base fournie par l'hébergement mutualisé |
| AssetMapper (importmap) | **Aucun build Node possible sur le serveur** — c'est la contrainte structurante |
| Tailwind via `symfonycasts/tailwind-bundle` | Compilé en local, seul le CSS produit est envoyé |
| ZXing + `BarcodeDetector` | Scan navigateur ; l'API native n'existe pas sur iOS/Safari, d'où le repli |

`composer config platform.php` est figé à **8.4.1** pour que le `vendor/`
construit en local reste valide sur le serveur.

## Démarrer

```bash
docker compose up -d
php -S 127.0.0.1:8000 -t public public/index.php
```

Créer un compte administrateur (mot de passe en saisie masquée, jamais en argument) :

```bash
php bin/console app:user:create --email=vous@exemple.fr --name="Votre nom"
```

Diagnostiquer la récupération de métadonnées :

```bash
php bin/console app:isbn:lookup 9782253004226
```

## Vérifier que tout est sain

```bash
php bin/phpunit                      # 6 tests, 15 assertions
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
```

## Règles métier à ne pas casser

- **Supprimer une catégorie ne doit jamais supprimer les livres.** Garanti en SQL :
  le `ON DELETE CASCADE` porte uniquement sur la table de jointure `book_category`.
  Le genre est en `ON DELETE SET NULL`. Couvert par un test.
- **Pas de catégories doublons.** `CategoryResolver` rapproche par slug normalisé
  puis par score de similarité (seuil `app.category_similarity_threshold`, 0.85).
  « science fiction » doit rejoindre « Science-fiction » existante. Couvert par un test.
- Le site vitrine est **entièrement public** ; seul `/admin` exige `ROLE_ADMIN`.
- Un prêt porte : nom, prénom, date de début, date de fin **nullable**.

## Pièges déjà rencontrés et résolus

Ne pas refaire ces erreurs, elles ont coûté du temps :

- **BnF SRU** : l'index `bib.isbn` renvoie toujours 0 résultat, et `bib.ean` aussi.
  Le seul qui fonctionne est **`bib.fuzzyIsbn`** (la BnF stocke les ISBN tiretés
  ou en ISBN-10).
- **BnF Dublin Core** : c'est de la prose catalographique. Titre avec mention de
  responsabilité (« 1984 / George Orwell ; trad. … »), auteur « Orwell, George
  (1903-1950). Auteur du texte », éditeur « Gallimard (Paris) ». Tout est nettoyé
  dans `BnfProvider`.
- **Google Books** : 429 sans clé API, 503 transitoires, et `totalItems: 0` sur une
  partie du catalogue français. Couverture partielle : c'est normal, pas un bug.
  Clé facultative dans `.env.local` (`GOOGLE_BOOKS_API_KEY`).
- **OpenLibrary** : lent (~7 s) et expose plus de 100 « subjects » contributifs
  inutilisables (cotes du type « Pr6029.r8 n49 2003 »). Filtrage et plafond
  obligatoires, sinon un seul scan crée des dizaines de catégories parasites.
- **Les trois sources sont interrogées en parallèle** (`createRequest` / `parse`
  séparés, le client HTTP Symfony étant paresseux) : 4 s au lieu de 17-25 s.
- `retry_failed` se configure sous `framework.http_client.default_options`,
  **pas** directement sous `http_client` — Symfony refuse l'option à cet endroit.
- Une URL passée à `path()` doit respecter les contraintes de la route : un
  marqueur `__ISBN__` échoue, d'où le gabarit `0000000000000`.
- Oublier `php bin/console tailwind:build` produit une erreur 500 sur toutes les pages.
- zsh : `rm -rf var/cache/*` déclenche une confirmation interactive ; préférer
  `php bin/console cache:clear`.

## Sécurité

- Les identifiants (FTP, SSH, base, clé API) ne doivent **jamais** transiter par
  le chat ni être versionnés. Ils vivent dans `.env.local`, qui est exclu de Git.
- `CoverDownloader` n'accepte que HTTPS, une liste blanche d'hôtes, 5 Mo maximum
  et un type MIME image vérifié — c'est ce qui empêche d'en faire un relais de
  requêtes arbitraires (SSRF).
- Le parseur XML de la BnF tourne avec `LIBXML_NONET` (pas de surface XXE).
- Suppression de livre protégée par jeton CSRF.

## Reste à faire

1. **Prêts** : `LoanType` + `Admin\LoanController` (prêter, rendre, prolonger),
   avec la contrainte d'un seul prêt actif par livre. `Book::getActiveLoan()` existe.
2. **Catégories** : CRUD et fusion de deux catégories proches.
   `CategoryResolver::suggest()` est écrit mais pas encore branché sur l'interface.
3. **Site vitrine** : recherche avancée publique. `BookRepository::search()` et
   `BookSearchCriteria` sont prêts ; il manque le contrôleur et les gabarits.
4. **Déploiement OVH** : tout construire en local, ne **rien** compiler sur le serveur.
   ```bash
   composer install --no-dev --optimize-autoloader
   php bin/console tailwind:build
   php bin/console asset-map:compile
   composer dump-env prod
   ```
   Puis envoyer. Tester si `rsync` over SSH passe chez OVH (bien plus fiable et
   incrémental), sinon FTP. Le *docroot* doit pointer sur `public/`.
   Vérifier la version MySQL réelle d'OVH pour ajuster `serverVersion`.
