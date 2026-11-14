# DataShare

- `backend/` : API Symfony (webapp)
- `frontend/` : Vue 3 + Vite (proxy `/api` → `http://127.0.0.1:8000`)

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
