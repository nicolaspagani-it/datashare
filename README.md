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
