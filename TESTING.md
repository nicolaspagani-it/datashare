# TESTING.md — Plan de tests

Ce document décrit **ce qui est testé, comment, avec quels critères d'acceptation, et comment lancer les tests**.
Résultats mesurés le 21 novembre 2026.

## 1. Vue d'ensemble

| | Backend (Symfony) | Frontend (Vue) |
|---|---|---|
| Outil | PHPUnit 13 | Vitest 5 + Vue Test Utils (jsdom) |
| Tests | **198** (750 assertions) | **251** dans 23 fichiers |
| Résultat | tous réussis | tous réussis |
| Couverture | lignes **95,3 %** (261/274), méthodes 94,3 % | instructions **99,7 %**, lignes **100 %** |
| Base de données | PostgreSQL réelle `datashare_test` (jamais la base de développement) | aucune : l'API est simulée |
| Durée | environ 25 s | environ 20 s |

Les tests du backend sont de vrais appels HTTP à l'application (`WebTestCase`), avec une vraie base : ils vérifient le
comportement de bout en bout côté serveur, pas seulement des fonctions isolées. Le frontend est testé composant par
composant, avec de faux appels réseau.

## 2. Stratégie : la pyramide de tests

| Niveau | Ce qu'on vérifie | Où |
|---|---|---|
| **Unitaire** | une classe ou une fonction seule : ticket de téléchargement, générateur de lien, stockage, DTO de validation, entités, utilitaires front (`format`, `expiry`, `fileIcons`) | `backend/tests/Service`, `Dto`, `Entity`, `Repository` ; `frontend/src/utils`, `components` |
| **Intégration (API)** | une route complète, du JSON reçu à la base de données : inscription, connexion, envoi, liste, suppression, lien public, déverrouillage, téléchargement | `backend/tests/Controller/Api`, `Command` |
| **Contrat (documentation)** | la spécification OpenAPI correspond à l'API réelle (routes, cadenas, schémas) et le fichier `docs/openapi.yaml` est à jour | `backend/tests/Documentation` |
| **Composants et pages** | ce que voit l'utilisateur : formulaires, erreurs sous les champs, états de la page (chargement, expiré, mot de passe), garde de routes | `frontend/src/**/*.test.js` |

Ce qui n'existe **pas** : tests de bout en bout dans un vrai navigateur (Playwright, Cypress), tests de charge
automatisés (la mesure manuelle est dans [PERF.md](PERF.md)), intégration continue (voir §8).

## 3. Critères d'acceptation par fonctionnalité

Une fonctionnalité est **acceptée** quand tous ses critères sont couverts par un test réussi.

| # | Fonctionnalité | Critères d'acceptation | Tests principaux |
|---|---|---|---|
| F1 | Inscription | email valide et unique (sans tenir compte de la casse) ; mot de passe de 8 caractères minimum ; mot de passe haché en base et jamais renvoyé ; chaque erreur rattachée à son champ ; doublon → 409 | `AuthControllerTest`, `RegisterRequestTest`, `AuthView.test.js` |
| F2 | Connexion | jeton JWT renvoyé pour de bons identifiants ; 401 sinon ; même message pour « email inconnu » et « mauvais mot de passe » ; jeton falsifié ou périmé refusé | `AuthControllerTest`, `auth.test.js`, `AuthView.test.js` |
| F3 | Envoi d'un fichier | fichier obligatoire, non vide, **1 Go maximum** ; durée de 1 à 7 jours ; mot de passe facultatif haché ; lien de partage renvoyé ; envoi anonyme possible ; jeton invalide → 401 ; nom piégé (`../x`) nettoyé ; type détecté d'après le contenu | `FileControllerTest`, `UploadRequestTest`, `UploadForm.test.js` |
| F4 | Mon espace | liste uniquement **mes** fichiers, du plus récent au plus ancien ; suppression réservée au propriétaire (404 pour les autres) ; fichiers expirés affichés « Expiré » | `FileControllerTest`, `MySpaceView.test.js`, `FileRow.test.js`, `files.test.js` |
| F5 | Page de téléchargement | lien inconnu → 404 ; expiré → 410 (même si le contenu est encore sur le disque) ; fichier sans mot de passe téléchargeable directement ; aucune donnée interne exposée (propriétaire, hash, nom sur disque) | `SharedFileControllerTest`, `DownloadView.test.js` |
| F6 | Fichier protégé | pas de téléchargement sans ticket valide ; ticket impossible à falsifier, valable pour **un seul** fichier et 10 minutes ; mauvais mot de passe → 403 ; **5 essais par 15 minutes** puis 429, même avec le bon mot de passe pendant le blocage ; réussite = compteur remis à zéro | `SharedFileControllerTest`, `DownloadTicketTest`, `DownloadView.test.js` |
| F7 | Purge | le contenu des fichiers expirés est supprimé du disque ; la ligne reste pour un fichier appartenant à un utilisateur (affiché « Expiré »), et disparaît pour un envoi anonyme ; les fichiers valides ne sont jamais touchés | `PurgeExpiredFilesCommandTest` |
| F8 | Documentation de l'API | toutes les routes `/api` sont documentées ; seuls `me`, `GET /files` et `DELETE /files/{id}` ont un cadenas, et ils répondent bien 401 sans jeton ; références et schémas valides ; `docs/openapi.yaml` à jour | `ApiDocumentationTest` |
| F9 | Sécurité des réponses | téléchargement toujours en pièce jointe, `nosniff`, jamais mis en cache partagé ; session expirée → retour à la connexion, sauf sur les routes publiques | `SharedFileControllerTest`, `api.test.js`, `router.test.js` |

La correspondance détaillée « propriété de sécurité → test » est dans [SECURITY.md](SECURITY.md), §10.

## 4. Environnement de test

- **Base séparée** : `datashare_test` (le suffixe `_test` est ajouté par `config/packages/doctrine.yaml`). Les tables
  sont vidées avant chaque test (ils sont indépendants), et le dossier de fichiers de test est `backend/var/uploads_test/`.
- **Limite de taille réduite à 1 Ko** en test (`when@test` dans `services.yaml`) : on teste « fichier trop gros »
  sans fabriquer 1 Go.
- **Hachage allégé** en test (`security.yaml`) : mêmes algorithmes, coût minimal, pour des tests rapides.
- **Limiteurs relevés** en test pour la connexion et l'envoi anonyme : le cache de test persiste d'une exécution à
  l'autre, des limites réalistes provoqueraient de faux échecs. La limite d'essais de mot de passe des fichiers,
  elle, est testée avec sa vraie valeur.
- **Configuration** dans `backend/.env.test.local` (ignoré par git) : `DATABASE_URL` et variables `JWT_*`. Le script
  d'installation le crée.
- Le frontend n'a besoin ni du backend ni d'une base : `api()` est remplacée par un faux.

## 5. Lancer les tests

### Backend

```bash
cd backend
php bin/console doctrine:database:create --env=test --if-not-exists    # une seule fois
php bin/console doctrine:migrations:migrate --env=test --no-interaction # après chaque nouvelle migration
php vendor/bin/phpunit                                                  # tous les tests
php vendor/bin/phpunit --filter SharedFileControllerTest                # une classe
php vendor/bin/phpunit --coverage-text                                  # avec couverture (extension pcov)
```

### Frontend

```bash
cd frontend
npm test                # une exécution
npm run test:watch      # relance à chaque modification
npm run test:coverage   # tableau dans le terminal + rapport HTML dans frontend/coverage/index.html
```

### Tout d'un coup, depuis une copie vierge

```powershell
.\scripts\install.ps1 -DbUser postgres -DbPassword 'mon-mot-de-passe' -RunTests
```
```bash
DB_USER=postgres DB_PASSWORD='…' ./scripts/install.sh --run-tests
```

### Dans VS Code

Extensions utilisables : *Vitest* (frontend) et *PHPUnit Test Explorer* (backend). Sous Windows, l'extension
Vitest verrouille `frontend/node_modules` tant qu'elle tourne : l'arrêter avant de relancer l'installation.

## 6. Résultats (21 novembre 2026)

| Contrôle | Résultat |
|---|---|
| `php vendor/bin/phpunit` | **OK, 198 tests, 750 assertions** |
| `npx vitest run` | **23 fichiers, 251 tests réussis** |
| Couverture backend | lignes 95,25 % (261/274), méthodes 94,28 % (66/70), classes 80 % (12/15) |
| Couverture frontend | instructions 99,74 %, branches 99,6 %, fonctions 97,47 %, lignes 100 % |
| Installation sur copie vierge (Windows 11, PowerShell 5.1 et Git Bash) | réussie, tests au vert |
| `composer audit`, `npm audit --omit=dev` | 0 vulnérabilité (détails dans [SECURITY.md](SECURITY.md), §8) |

**Seuil retenu :** 100 % des tests réussis, et couverture des lignes supérieure à 90 % côté backend, 95 % côté
frontend. Une livraison en dessous de ces seuils est refusée.

## 7. Ce qui n'est pas testé automatiquement (et pourquoi)

| Sujet | Situation |
|---|---|
| Limite d'essais de **connexion** (5 échecs / 15 min) et d'**envois anonymes** (10 / heure) | vérifiées à la main sur le serveur de développement (6e connexion refusée avec « Trop de tentatives… ») ; désactivées en test à cause du cache persistant. Piste : un cache `array` dédié aux tests |
| Envoi réel de **1 Go** | non testé : la limite est vérifiée avec 1 Ko. Les limites PHP (`upload_max_filesize`, `post_max_size`) dépendent du serveur |
| Navigateur réel (rendu, copier le lien, téléchargement effectif) | aucun test de bout en bout ; vérifié manuellement |
| `scripts/install.sh` | écrit et relu, mais **jamais exécuté** sur un vrai Linux ou macOS |
| Condition de concurrence purge / téléchargement | rare, non couverte (voir revue de code) |

## 8. Intégration continue (non mise en place)

Pas de pipeline pour l'instant. Un workflow GitHub Actions minimal lancerait, sur chaque *push* :
`composer install`, `composer audit`, `phpunit` (avec un service PostgreSQL), puis `npm ci`, `npm audit --omit=dev`,
`npm test`. C'est la suite logique de ce plan.

## 9. Méthode quand un test échoue

1. Relancer **ce test seul** (`--filter`) pour savoir s'il échoue de façon constante.
2. `ApiDocumentationTest` rouge après un changement d'API : lancer `composer docs` puis commiter `docs/openapi.yaml`.
3. Erreur de base de données : relancer les migrations en environnement de test (§5).
4. Ne jamais « corriger » un test pour le faire passer sans comprendre pourquoi il échouait : un test rouge est
   d'abord une information sur le code.
