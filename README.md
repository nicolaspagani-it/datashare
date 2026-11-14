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
