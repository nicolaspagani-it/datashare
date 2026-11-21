# PERF.md — Performances

Mesures réalisées le 21 novembre 2026 sur un **poste de développement** (Windows 11, PHP 8.4.6 en ligne de commande,
PostgreSQL 18 local). Elles servent à **repérer les ordres de grandeur et les points faibles**, pas à promettre des
performances de production : voir les limites au §6.

## 1. Méthode

- **Serveur** : application en mode production (`APP_ENV=prod APP_DEBUG=0`, cache réchauffé avec
  `php bin/console cache:warmup`), servie par le serveur intégré de PHP avec `opcache` et JIT activés :
  ```bash
  APP_ENV=prod APP_DEBUG=0 php -d zend_extension=opcache -d opcache.enable_cli=1 \
      -d opcache.jit=tracing -d opcache.jit_buffer_size=64M -S 127.0.0.1:8001 -t public
  ```
- **Outil** : [`scripts/perf/load-test.mjs`](scripts/perf/load-test.mjs), un petit script Node sans dépendance
  (5 requêtes d'échauffement non comptées, puis N requêtes avec C en parallèle, corps de réponse lu en entier).
  ```bash
  node scripts/perf/load-test.mjs http://127.0.0.1:8001/api/health 300 10
  ```
- **Données** : un fichier texte de 46 octets, envoyé une fois, puis supprimé après les mesures.
- **Navigateur** : build de production (`npm run build`) servi par `vite preview`, mesures avec l'API
  *Navigation Timing* et *Resource Timing* du navigateur intégré.

## 2. Test de performance de l'API

300 requêtes par ligne. « 1 » = une requête à la fois, « 10 » = dix en parallèle.

| Endpoint | Parallèles | Débit | Médiane | p95 | p99 | Max | Erreurs |
|---|---|---|---|---|---|---|---|
| `GET /api/health` (sans base) | 1 | 31,5 req/s | 30,9 ms | 35,2 ms | 64,9 ms | 66,1 ms | 0 |
| `GET /api/health` | 10 | 32,0 req/s | 304,9 ms | 339,5 ms | 356,9 ms | 357,5 ms | 0 |
| `GET /api/shared/{token}` (1 lecture en base) | 1 | 10,1 req/s | 82,8 ms | 214,2 ms | 237,7 ms | 254,6 ms | 0 |
| `GET /api/shared/{token}` | 10 | 10,1 req/s | 978,8 ms | 1 257 ms | 1 341 ms | 1 359 ms | 0 |
| `GET /api/shared/{token}/download` (1 lecture en base + envoi du fichier) | 1 | 10,1 req/s | 81,6 ms | 220,4 ms | 238,4 ms | 271,7 ms | 0 |
| `GET /api/shared/{token}/download` | 10 | 10,5 req/s | 934,7 ms | 1 222 ms | 1 290 ms | 1 293 ms | 0 |

Aucune requête en erreur : toutes ont répondu `200`.

### Interprétation

1. **Le débit ne bouge pas quand on passe de 1 à 10 requêtes en parallèle** (32 req/s, puis 10 req/s) : c'est le
   signe que le serveur de test traite **une requête à la fois** (le serveur intégré de PHP est mono-processus). Avec
   10 clients, chaque requête attend les 9 autres : la médiane est environ 10 fois plus longue (304 ms au lieu de
   31 ms). Ce n'est donc **pas** un défaut du code mais la limite de l'outil de test.
2. **Le coût d'une requête sans base est d'environ 31 ms** (démarrage de Symfony, sécurité, routage). Celui d'une
   requête avec lecture en base est d'environ **83 ms en médiane**, soit environ **50 ms de plus**, avec un p95 à
   214 ms. L'écart entre la médiane et le p95 s'explique **probablement** par la connexion à PostgreSQL ouverte à
   chaque requête (le serveur PHP ne garde pas de connexion entre deux requêtes) : c'est une hypothèse, non vérifiée
   par une mesure séparée.
3. **Le téléchargement coûte autant que la simple consultation** (82 ms contre 83 ms) : sur un fichier minuscule, le
   temps est dominé par Symfony et la base, pas par l'envoi du fichier. Sur un gros fichier, `BinaryFileResponse`
   envoie en flux sans charger le fichier en mémoire, donc la mémoire reste constante (non mesuré ici).
4. **Effet de `opcache`** : une première série de mesures **sans** opcache a donné 218 ms par requête (et
   4,6 req/s) sur `/api/health`, soit **7 fois plus lent**. En production, `opcache` doit être activé.
5. **Capacité estimée** : avec un seul processus PHP, environ 10 consultations par seconde, soit près de
   **36 000 par heure**. En production, PHP-FPM lance plusieurs processus : le débit augmente à peu près en
   proportion du nombre de cœurs, tant que PostgreSQL suit.

### Budget de performance de l'API (objectifs)

| Indicateur | Budget | Mesuré (1 requête à la fois) | Respecté |
|---|---|---|---|
| Consultation d'un lien (`GET /api/shared/{token}`), médiane | ≤ 100 ms | 83 ms | oui |
| Consultation d'un lien, p95 | ≤ 250 ms | 214 ms | oui |
| Requête sans base (`/api/health`), médiane | ≤ 50 ms | 31 ms | oui |
| Taux d'erreur | 0 % | 0 % | oui |

## 3. Performances du frontend

Mesures du build de production, chargement à froid de la page d'accueil (navigateur intégré, serveur local) :

| Indicateur | Mesure |
|---|---|
| Temps jusqu'au premier octet (TTFB) | 13 ms |
| `DOMContentLoaded` | 107 ms |
| Événement `load` | 149 ms |
| Nombre de requêtes | 6 (1 script, 1 feuille de style, 4 polices) |
| Poids transféré total (compressé) | **113 ko** |

### Taille du build (`npm run build`)

| Fichier | Taille brute | Compressé (gzip) |
|---|---|---|
| `index-*.js` (Vue, Pinia, vue-router, l'application) | 123,3 ko | **45,9 ko** |
| `index-*.css` | 17,8 ko | 3,8 ko |
| Polices (DM Sans 300/400/600/700, Inter 400) | 14 à 48 ko chacune, `woff2` | — |
| Dossier `dist/` complet | 637 ko | — |

### Budget de performance du frontend (objectifs)

| Indicateur | Budget | Mesuré | Respecté |
|---|---|---|---|
| JavaScript compressé | ≤ 100 ko | 45,9 ko | oui |
| CSS compressé | ≤ 20 ko | 3,8 ko | oui |
| Poids total au premier chargement | ≤ 250 ko | 113 ko | oui |
| Nombre de requêtes au premier chargement | ≤ 10 | 6 | oui |
| `load` en local | ≤ 1 s | 149 ms | oui |

Ce qui aide déjà : les polices sont **installées en local** (aucun appel à un CDN ni à Google Fonts), l'application
est un seul fichier JavaScript, les fichiers sont nommés avec une empreinte (cache longue durée possible), et le
navigateur reçoit les fichiers en flux sans passer par JavaScript.

## 4. Captures et traces

- **Résultats bruts de l'API** : la sortie du script `scripts/perf/load-test.mjs` (tableau du §2). Exemple :
  ```
  URL           : http://127.0.0.1:8001/api/shared/1i3CFRm4
  Requêtes      : 300 (1 en parallèle)
  Débit         : 10.1 requêtes/s
  Médiane (p50) : 82.8 ms
  p95           : 214.2 ms
  p99           : 237.7 ms
  Statuts       : 200 × 300
  ```
- **Navigateur** : onglet *Réseau* des outils de développement (6 requêtes, 113 ko) et onglet *Performance* /
  Lighthouse pour les indicateurs d'affichage. Le navigateur intégré ne remontait pas les indicateurs d'affichage
  (*First Contentful Paint*, *Largest Contentful Paint*) : **à compléter par une capture Lighthouse** dans Chrome
  pour la présentation.
- **Serveur** : en développement, le *profiler* Symfony (`/_profiler`) donne le temps par requête, les requêtes SQL et
  la mémoire. En production, les journaux partent sur `stderr` (`monolog.yaml`).

## 5. Pistes d'amélioration (par ordre d'intérêt)

| # | Piste | Gain attendu |
|---|---|---|
| 1 | **PHP-FPM + Nginx/Apache** à la place du serveur intégré, `opcache` activé, `opcache.preload` | débit multiplié par le nombre de processus ; latence plus stable |
| 2 | **Connexions PostgreSQL persistantes** (`persistent: true` dans `doctrine.yaml`) ou un *pooler* (PgBouncer) | supprime l'écart médiane / p95 observé sur les routes avec base (≈ 50 ms) |
| 3 | `composer install --no-dev --optimize-autoloader --classmap-authoritative` | chargement des classes plus rapide |
| 4 | Servir le frontend par un serveur web avec **compression Brotli/gzip** et en-têtes de cache longue durée sur les fichiers à empreinte | JavaScript de 123 ko envoyé en 46 ko ; rechargements instantanés |
| 5 | Délégation de l'envoi du fichier au serveur web (`X-Accel-Redirect` / `X-Sendfile`) | PHP libéré pendant le téléchargement d'un gros fichier |
| 6 | Cache partagé (Redis) pour les limiteurs d'essais | cohérence avec plusieurs serveurs |

## 6. Limites de ces mesures

- **Une seule machine** fait tourner le serveur, la base et l'outil de mesure : ils se disputent le processeur.
- **Serveur mono-processus** : les résultats « 10 en parallèle » mesurent la file d'attente, pas la capacité d'un
  vrai serveur. Les mesures « 1 en parallèle » sont les plus représentatives de la latence du code.
- **Fichier minuscule** : le débit d'envoi et de téléchargement d'un fichier de 1 Go n'est pas mesuré ; il dépend du
  disque, du réseau et de `upload_max_filesize` / `post_max_size`.
- **Pas de test d'envoi** (`POST /api/files`) : il est limité à 10 par heure et par IP pour les visiteurs anonymes.
- **Une base presque vide** : avec des millions de lignes, l'index unique sur `token` garde la consultation rapide,
  mais la liste « Mes fichiers » (`GET /api/files`, sans pagination) grossirait avec le nombre de fichiers de
  l'utilisateur.
- Les mesures du navigateur viennent d'un navigateur intégré, sur `localhost` : pas de latence réseau réelle.
