# DataShare

Partage de fichiers par lien : on envoie un fichier (1 Go maximum), avec une durée de conservation de 1 à 7 jours
et un mot de passe optionnel, et on obtient un lien de partage.

- `backend/` : API Symfony 8 (PHP 8.4, PostgreSQL, authentification JWT)
- `frontend/` : Vue 3 + Vite (proxy `/api` → `http://127.0.0.1:8000`)
- `scripts/` : installation et configuration de la base de données

## Installation

### Prérequis

| Outil | Version | Remarque |
|---|---|---|
| PHP | 8.4 ou plus | extensions : `ctype`, `iconv`, `mbstring`, `openssl`, `sodium`, `pdo_pgsql`, `fileinfo` |
| Composer | 2.x | https://getcomposer.org/download/ |
| Node.js | 20.19+ ou 22.12+ | npm inclus |
| PostgreSQL | 16 ou plus | testé avec la version 18 |

Pour lancer la couverture de code (facultatif) : l'extension PHP `pcov`.

### Installation automatique (recommandée)

Le script vérifie les prérequis, installe les dépendances, crée la configuration locale, génère les clés JWT,
crée les bases (développement et test) et applique les migrations. Il est **rejouable** : on peut le relancer
sans rien casser.

**Windows (PowerShell)**

```powershell
.\scripts\install.ps1 -DbUser postgres -DbPassword 'mon-mot-de-passe'
```

**Linux / macOS**

```bash
DB_USER=postgres DB_PASSWORD='mon-mot-de-passe' ./scripts/install.sh
```

| Option PowerShell | Variable (Linux/macOS) | Défaut | Rôle |
|---|---|---|---|
| `-DbHost` | `DB_HOST` | `127.0.0.1` | hôte PostgreSQL |
| `-DbPort` | `DB_PORT` | `5432` | port PostgreSQL |
| `-DbUser` | `DB_USER` | `postgres` | utilisateur (doit pouvoir créer une base) |
| `-DbPassword` | `DB_PASSWORD` | aucun | mot de passe |
| `-DbName` | `DB_NAME` | `datashare` | nom de la base (la base de test sera `<nom>_test`) |
| `-FrontUrl` | `FRONT_URL` | `http://localhost:5173` | adresse du frontend, pour les liens de partage |
| `-Force` | `--force` | | régénère `backend/.env.local` **et** les clés JWT |
| `-SkipFrontend` | `--skip-frontend` | | n'installe pas le frontend |
| `-RunTests` | `--run-tests` | | lance les tests à la fin |

Le script ne modifie **jamais** `backend/.env` : toute la configuration locale est dans `backend/.env.local`
(ignoré par git).

### Créer l'utilisateur et la base PostgreSQL (si besoin)

Si tu n'as pas d'utilisateur PostgreSQL pour l'application, un administrateur peut le créer, avec sa base, grâce à
`scripts/db/init.sql` (rejouable, il ne recrée pas ce qui existe) :

```bash
psql -U postgres -v db_user=datashare -v db_password='un-mot-de-passe' -v db_name=datashare -f scripts/db/init.sql
```

Puis lance l'installation avec `-DbUser datashare -DbPassword 'un-mot-de-passe'`. L'utilisateur reçoit le droit
`CREATEDB`, nécessaire pour la base de test. Les **tables** sont créées par les migrations Doctrine, pas par ce fichier.

### Installation manuelle

```bash
# 1. backend
cd backend
composer install
cp .env.local.example .env.local          # puis remplace les valeurs (base, secrets)
php bin/console lexik:jwt:generate-keypair
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate

# 2. base de test (voir la section Tests ci-dessous pour .env.test.local)
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --env=test

# 3. frontend
cd ../frontend && npm ci
```

### Dépannage

| Symptôme | Cause et solution |
|---|---|
| « Extensions PHP manquantes » | active-les dans `php.ini` (retire le `;` devant `extension=pdo_pgsql`, etc.), puis relance |
| « Impossible de se connecter à PostgreSQL » | le serveur n'est pas démarré, ou l'hôte, le port, l'utilisateur ou le mot de passe sont faux |
| `non-existent parameter "40ss"` | un `%` du mot de passe dans `DATABASE_URL` n'est pas doublé (`%%40`). Les scripts le font seuls ; à la main, encode le mot de passe puis double chaque `%` |
| La connexion répond 500 « private key/passphrase » | `JWT_PASSPHRASE` ne correspond plus aux clés : relance l'installation avec `-Force` / `--force` (régénère les deux) |
| « Des programmes Node.js utilisent encore le dossier frontend » | un `npm run dev` (ou l'extension Vitest de VS Code) tourne encore : arrête-le (Ctrl+C), puis relance l'installation. Sous Windows, il verrouille `frontend\node_modules` |
| `'vite' n'est pas reconnu` au `npm run dev` | `frontend\node_modules` est incomplet (installation interrompue). Arrête tout serveur en cours, puis : `cd frontend`, `npm ci` |
| Un envoi de gros fichier est refusé | `upload_max_filesize` et `post_max_size` de `php.ini` doivent être d'au moins 1 Go |

L'installation a été testée sur Windows 11 (PowerShell 5.1 et Git Bash) à partir d'une copie vierge du projet :
installation, 181 tests PHPUnit et 250 tests Vitest au vert. `install.sh` n'a pas été exécuté sur un vrai Linux ou macOS.

## Lancer en développement

```bash
# terminal 1
cd backend && symfony serve --no-tls -d   # ou: php -S 127.0.0.1:8000 -t public

# terminal 2
cd frontend && npm run dev                 # http://localhost:5173
```

## API des fichiers

| Route | Accès | Rôle |
|---|---|---|
| `POST /api/files` | public (un token JWT, s'il est fourni, rattache le fichier à l'utilisateur) | envoyer un fichier (`multipart/form-data` : `file`, `expiration` = 1 à 7 jours, `password` optionnel) |
| `GET /api/files` | connecté | liste de mes fichiers |
| `DELETE /api/files/{id}` | connecté, propriétaire | supprimer un fichier |

### Récupérer un fichier à partir de son lien (public, sans compte)

| Route | Rôle |
|---|---|
| `GET /api/shared/{token}` | informations : nom, taille, type, `protected`, `expiresAt` |
| `POST /api/shared/{token}/unlock` | vérifie le mot de passe (`{"password": "..."}`) et renvoie `{"downloadUrl": "..."}` |
| `GET /api/shared/{token}/download` | télécharge le fichier (en flux, reprise possible avec `Range`) |

- Un fichier **sans mot de passe** se télécharge directement. Pour un fichier **protégé**, il faut d'abord appeler `unlock` : l'URL renvoyée contient un ticket signé, valable 10 minutes, qui ne sert que pour ce fichier.
- Erreurs : `404` lien inconnu, `410` fichier expiré (ou contenu supprimé), `403` mot de passe incorrect ou ticket invalide, `422` mot de passe manquant, `429` trop d'essais.
- Essais de mot de passe limités : 5 par tranche de 15 minutes, par lien et par adresse IP (`config/packages/rate_limiter.yaml`). Un essai réussi remet le compteur à zéro.
- Le fichier est toujours envoyé en pièce jointe (`Content-Disposition: attachment`), jamais affiché dans le navigateur.

La page correspondante côté Vue est `/<token>` (`frontend/src/views/DownloadView.vue`) : publique, elle appelle ces trois routes sans envoyer de token de connexion.

### Détails

- Taille maximale : 1 Go (`app.max_upload_size` dans `backend/config/services.yaml`). PHP doit accepter au moins cette taille (`upload_max_filesize` et `post_max_size`).
- Les fichiers sont stockés dans `backend/var/uploads/` (hors de `public/`), sous un nom aléatoire.
- Le mot de passe optionnel d'un fichier n'est jamais stocké en clair : seul son hash l'est.
- Le lien de partage est `<FRONT_URL>/<token>`. `FRONT_URL` se définit dans `backend/.env.local` (par défaut `http://localhost:5173`).
- Les fichiers expirés sont supprimés du disque par une commande, à planifier (toutes les heures par exemple, avec le Planificateur de tâches de Windows ou cron). La ligne reste en base : l'utilisateur voit « Expiré » dans sa liste.

```bash
cd backend && php bin/console app:files:purge-expired
```

## Tests

### Backend (PHPUnit)

Les tests utilisent une base séparée, `datashare_test` (suffixe ajouté par `config/packages/doctrine.yaml`),
et lisent `backend/.env.test.local` (ignoré par git : `.env.local` n'est pas chargé en mode test).
Ce fichier doit définir `DATABASE_URL` et les variables `JWT_*`.

```bash
cd backend
php bin/console doctrine:database:create --env=test --if-not-exists   # une seule fois
php bin/console doctrine:migrations:migrate --env=test --no-interaction  # à refaire après une nouvelle migration
php vendor/bin/phpunit
php vendor/bin/phpunit --coverage-text   # avec couverture de code (nécessite l'extension PHP "pcov")
```

### Frontend (Vitest + Vue Test Utils)

```bash
cd frontend
npm test              # une exécution
npm run test:watch    # relance automatiquement à chaque modification
npm run test:coverage # couverture de code : tableau dans le terminal + rapport HTML dans frontend/coverage/index.html
```
