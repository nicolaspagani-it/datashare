# Sécurité et gestion des accès

Ce document décrit comment DataShare protège les comptes et les fichiers, et surtout **ce qui n'est pas (encore) protégé**.
Chaque affirmation correspond à du code ou à un test existant ; les limites connues sont listées à la fin, sans les masquer.

- [1. Vue d'ensemble](#1-vue-densemble)
- [2. Authentification](#2-authentification)
- [3. Gestion des accès](#3-gestion-des-accès)
- [4. Protection des fichiers partagés](#4-protection-des-fichiers-partagés)
- [5. Le ticket de téléchargement](#5-le-ticket-de-téléchargement)
- [6. Validation des données et erreurs](#6-validation-des-données-et-erreurs)
- [7. Secrets et configuration](#7-secrets-et-configuration)
- [8. Dépendances](#8-dépendances)
- [9. Limites connues et pistes d'amélioration](#9-limites-connues-et-pistes-damélioration)
- [10. Comment ces points sont vérifiés](#10-comment-ces-points-sont-vérifiés)
- [11. Signaler une faille](#11-signaler-une-faille)

## 1. Vue d'ensemble

| Ce qu'on protège | Comment |
|---|---|
| Les **comptes** | mot de passe haché (bcrypt), connexion par jeton JWT signé, durée limitée |
| Les **fichiers d'un utilisateur** | seul le propriétaire les liste et les supprime |
| Les **fichiers partagés** | lien secret impossible à deviner, mot de passe optionnel (haché), ticket de téléchargement signé, expiration |
| Le **serveur** | validation de toutes les entrées côté serveur, taille limitée, stockage hors du dossier public, essais de mot de passe limités |

Principe général : **tout ce qui est sous `/api` est refusé aux visiteurs, sauf une liste explicite de routes publiques**
(refus par défaut).

## 2. Authentification

**Jeton JWT** (bundle `lexik/jwt-authentication-bundle`) :

- À la connexion (`POST /api/login`), le serveur renvoie un jeton signé en **RS256** (clé privée / clé publique).
  Le frontend l'envoie ensuite dans l'en-tête `Authorization: Bearer <jeton>`.
- Le jeton est **valable 1 heure**. Il n'y a pas de session côté serveur (pare-feu `stateless`) : rien à voler dans un
  cookie, et pas de jeton CSRF à gérer, puisque le navigateur n'envoie jamais le jeton tout seul.
- La clé privée est chiffrée par une phrase secrète (`JWT_PASSPHRASE`), générée au hasard par les scripts
  d'installation. Les clés ne sont jamais dans git (`backend/config/jwt/*.pem` est ignoré).
- Un jeton falsifié, modifié ou expiré est refusé avec `401` (testé : `testMeRejectsATamperedToken`).

**Mots de passe des comptes :**

- Hachés par le composant de sécurité de Symfony (algorithme `auto` : bcrypt, coût 13). Le mot de passe n'est **jamais**
  stocké ni renvoyé en clair ; aucune réponse de l'API ne contient de hash.
- Longueur minimale : 8 caractères (maximum 4096, pour éviter d'envoyer des mots de passe démesurés).
- Une connexion refusée donne le **même message** pour « email inconnu » et « mauvais mot de passe »
  (« Identifiants invalides. »), pour ne pas révéler quels comptes existent.

## 3. Gestion des accès

Règles dans `backend/config/packages/security.yaml` (la première qui correspond s'applique) :

| Route | Qui peut l'utiliser |
|---|---|
| `POST /api/register`, `POST /api/login`, `GET /api/health` | **tout le monde** |
| `GET /api/doc.json` et la page `/docs/` (documentation) | **tout le monde** |
| `POST /api/files` (envoyer un fichier) | **tout le monde** ; avec un jeton valide, le fichier est rattaché au compte |
| `GET /api/shared/{token}`, `.../unlock`, `.../download` (récupérer un fichier par son lien) | **tout le monde** possédant le lien |
| `GET /api/me`, `GET /api/files`, `DELETE /api/files/{id}` | **utilisateur connecté** uniquement |
| **toute autre route sous `/api`** | **utilisateur connecté** (refus par défaut) |

- **Propriété des fichiers :** `GET /api/files` ne renvoie que les fichiers du compte connecté. `DELETE /api/files/{id}`
  répond `404` (et non `403`) pour le fichier d'un autre utilisateur ou un fichier anonyme : on ne révèle pas qu'il
  existe.
- **Cohérence avec la documentation :** un test compare, route par route, le cadenas affiché dans Swagger et le
  comportement réel sans jeton (`ApiDocumentationTest::testDocumentedAuthenticationMatchesTheRealSecurity`).
- **Côté navigateur :** les pages « Mon espace » sont protégées par un garde de navigation. C'est un confort d'usage, pas
  une sécurité : la vraie protection est celle de l'API ci-dessus. Pour les appels publics (téléchargement par lien),
  le frontend n'envoie aucun jeton (`auth: false`), afin qu'un vieux jeton expiré ne déconnecte pas un visiteur.

## 4. Protection des fichiers partagés

| Mesure | Détail |
|---|---|
| **Lien secret** | 8 caractères pris au hasard parmi 62 (`random_int`, générateur sûr) : environ 218 000 milliards de combinaisons, impossible à deviner |
| **Mot de passe facultatif** | jamais stocké en clair : seul son hash l'est ; vérifié avec `PasswordHasher::verify` (comparaison sûre) |
| **Essais limités** | 5 essais de mot de passe par tranche de 15 minutes, **par lien et par adresse IP** ; au-delà : `429` avec `Retry-After`. Un essai réussi remet le compteur à zéro. Un champ vide ne compte pas. Même le bon mot de passe est refusé pendant le blocage |
| **Expiration** | de 1 à 7 jours. Vérifiée **à chaque appel** (même avec un ticket valide) : un fichier expiré répond `410`. Une commande (`app:files:purge-expired`) supprime ensuite le contenu du disque |
| **Stockage** | dans `backend/var/uploads/`, **hors du dossier public** : un fichier n'est jamais accessible par une adresse directe, donc jamais exécuté par le serveur |
| **Nom sur le disque** | 32 caractères aléatoires : le nom d'origine n'existe qu'en base |
| **Nom d'origine** | nettoyé (un chemin comme `../../x.jpg` devient `x.jpg`) et limité à 255 caractères |
| **Type du fichier** | détecté d'après le **contenu** (`finfo`), pas d'après ce que déclare le navigateur |
| **Téléchargement** | toujours en pièce jointe (`Content-Disposition: attachment`), avec `X-Content-Type-Options: nosniff` et `Cache-Control: private, no-store` : un fichier HTML ou SVG est enregistré, jamais affiché ni exécuté dans le navigateur |
| **Taille** | 1 Go maximum, vérifiée **côté serveur** (indépendamment du contrôle du frontend) ; fichier vide refusé |
| **Informations publiques** | `GET /api/shared/{token}` ne renvoie jamais le propriétaire, le nom sur le disque ni le hash du mot de passe |

## 5. Le ticket de téléchargement

**Pourquoi :** un téléchargement est une simple adresse (`GET`). Elle ne peut pas contenir le mot de passe : il resterait
dans l'historique du navigateur et les journaux, et pourrait être copié avec le lien. Le mot de passe est donc vérifié
**avant**, séparément, et le serveur remet un **ticket** : un laissez-passer temporaire.

**Parcours d'un fichier protégé :**

1. `POST /api/shared/{token}/unlock` avec le mot de passe → le serveur le vérifie.
2. S'il est correct, il répond une adresse de téléchargement contenant le ticket :
   `/api/shared/{token}/download?ticket=1794742332.f7bdcff1…`
3. Le navigateur ouvre cette adresse, le serveur vérifie le ticket, le fichier est envoyé (en flux, sans passer par la
   mémoire de JavaScript : 1 Go possible).

**Forme du ticket :** `<heure d'expiration>.<signature>`

- l'**heure d'expiration** est celle de la délivrance + **10 minutes** ;
- la **signature** est un HMAC-SHA256 de `lien|heure d'expiration`, calculé avec une clé secrète : **le hash du mot de
  passe du fichier**, qui n'existe que sur le serveur et est différent pour chaque fichier.

**Ce que cela garantit :**

| Tentative | Résultat |
|---|---|
| fabriquer un ticket sans connaître le secret | impossible : la signature ne peut pas être calculée |
| modifier l'heure pour le prolonger | signature invalide, ticket refusé |
| utiliser le ticket d'un autre fichier | refusé : la signature dépend du lien et du secret propres à chaque fichier |
| utiliser un ticket expiré | refusé (10 minutes) |
| utiliser un ticket après l'expiration du fichier | `410` : l'expiration est revérifiée à chaque appel |
| comparer la signature en mesurant des durées | impossible : comparaison en temps constant (`hash_equals`) |

Le serveur **ne stocke aucun ticket** : il refait le calcul pour vérifier. Un ticket n'est ni le mot de passe, ni le jeton
de connexion (JWT) : il ne prouve rien sur l'utilisateur, il ne vaut que pour **un fichier**.

**Limite connue :** pendant ses 10 minutes, quiconque possède l'adresse complète (avec le ticket) peut télécharger le
fichier sans mot de passe. Il faut donc partager le lien normal (`/UhGyr4Kd`), qui redemande toujours le mot de passe,
et non l'adresse de téléchargement.

## 6. Validation des données et erreurs

- **Toute entrée est validée côté serveur** (composant `validator`), quelle que soit la validation du frontend : email
  valide, mot de passe de 8 à 4096 caractères, durée de conservation de 1 à 7 jours, fichier présent, non vide et de
  1 Go maximum.
- Les erreurs sont renvoyées **par champ** (`violations` avec le nom du champ), avec des messages en français. Elles ne
  contiennent aucune information interne en production.
- Les requêtes sont toujours traitées comme du JSON ou du formulaire : pas de requêtes SQL écrites à la main (Doctrine ORM
  avec paramètres liés), donc pas d'injection SQL par construction.
- L'affichage côté Vue passe par l'échappement automatique des gabarits : les noms de fichiers saisis par des tiers ne
  sont jamais interprétés comme du HTML.

## 7. Secrets et configuration

- **Aucun secret dans git.** Le fichier `.env` (valeurs par défaut) n'est jamais modifié ; la configuration locale est
  dans `backend/.env.local` et `backend/.env.test.local`, ignorés par git. `backend/config/jwt/*.pem` l'est aussi.
- Les scripts d'installation (`scripts/install.ps1`, `scripts/install.sh`) génèrent **au hasard** `APP_SECRET` et la phrase
  secrète JWT, et créent ces fichiers. Voir `backend/.env.local.example` pour une installation manuelle.
- Le mot de passe de la base de données se trouve dans `DATABASE_URL`, dans `.env.local` uniquement.
- En mode développement (`APP_ENV=dev`), Symfony affiche des détails techniques dans les erreurs : **ne jamais déployer
  en mode `dev`**.

## 8. Dépendances

| Contrôle | Résultat |
|---|---|
| `composer audit` (backend) | **aucune vulnérabilité connue** |
| `npm audit --omit=dev` (frontend) | **8 alertes « high », toutes issues d'une seule cause** : `picomatch` ≤ 4.0.7, vulnérable à un déni de service par expression régulière ([GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)) |

À propos de l'alerte `picomatch` : elle est tirée par les **outils de construction** (Vite, vue-router). Elle n'est
**pas présente dans le code envoyé au navigateur** (0 occurrence dans le dossier `dist/` après `npm run build`), et elle
exigerait de fournir des motifs de fichiers piégés à l'outil de construction, ce qu'un visiteur ne peut pas faire. Le risque
réel pour l'application est donc faible. **Aucune version corrigée n'existe à ce jour** (la 4.0.7 installée est la plus
récente) : la « correction » proposée par `npm audit` serait un retour en arrière de vue-router vers une version
majeure plus ancienne, que nous écartons. Piste : surveiller la sortie d'un correctif et relancer `npm audit`.

## 9. Limites connues et pistes d'amélioration

Ce que le projet ne fait **pas** encore, du plus important au moins important :

| # | Limite | Risque | Piste |
|---|---|---|---|
| 1 | ~~Aucune limite d'essais sur `POST /api/login`~~ **Corrigé** : `login_throttling` (5 échecs par 15 minutes, par email + adresse IP) | — | — |
| 2 | **Quota partiel** : les visiteurs anonymes sont limités à 10 envois par heure et par IP, mais les comptes connectés et l'espace disque total ne sont pas plafonnés | un compte (gratuit) peut remplir le disque avec des fichiers de 1 Go | quota par compte, espace disque total plafonné |
| 3 | **Jeton JWT conservé dans le `localStorage`** du navigateur | une faille XSS permettrait de le lire (le mot de passe de compte, lui, n'est jamais stocké) | cookie `HttpOnly` + `SameSite`, et en-tête `Content-Security-Policy` |
| 4 | **Pas de révocation ni de renouvellement du jeton** (1 heure fixe) | un jeton volé reste valable jusqu'à son expiration ; la « déconnexion » oublie le jeton côté navigateur seulement | jeton court + jeton de renouvellement (`refresh token`), liste de jetons révoqués |
| 5 | **HTTPS et en-têtes de sécurité non configurés** (HSTS, CSP, `X-Frame-Options`) | à régler au déploiement : sans HTTPS, jetons et mots de passe circulent en clair | terminaison TLS sur le serveur web ; en-têtes ajoutés par le serveur web ou un écouteur Symfony |
| 6 | **Adresse IP derrière un proxy** : `trusted_proxies` n'est pas configuré | derrière un reverse proxy, toutes les requêtes semblent venir de la même IP : la limite d'essais de mot de passe deviendrait globale | déclarer les proxys de confiance |
| 7 | **L'inscription révèle qu'un email existe** (`409`) | énumération des comptes (la connexion, elle, ne le révèle pas) | compromis assumé pour l'expérience utilisateur ; sinon, inscription par email de confirmation |
| 8 | **Pas de vérification de l'email** ni de politique de mot de passe au-delà de 8 caractères | comptes avec de faux emails, mots de passe faibles | email de confirmation, liste de mots de passe courants refusés |
| 9 | **Fichiers non chiffrés sur le disque** et **non analysés** (antivirus) | un accès au serveur donne accès au contenu ; un fichier malveillant peut être partagé | chiffrement au repos, analyse antivirus à l'envoi |
| 10 | **Limiteur d'essais stocké dans le cache du serveur** (fichiers) | avec plusieurs serveurs, chacun compterait de son côté | cache partagé (Redis) |
| 11 | **Purge des fichiers expirés non planifiée** | l'API refuse déjà un fichier expiré (410), mais le contenu reste sur le disque tant que la commande n'est pas lancée | tâche planifiée toutes les heures (`app:files:purge-expired`) |
| 12 | **Aucun journal d'audit** (qui a téléchargé quoi) | pas de traçabilité | journal des téléchargements et des échecs d'authentification |
| 13 | Une route inexistante sous `/api` répond en HTML | cosmétique | route de secours en JSON |

## 10. Comment ces points sont vérifiés

| Propriété | Tests (backend, `php vendor/bin/phpunit`) |
|---|---|
| jeton falsifié, absent ou invalide refusé | `AuthControllerTest` : `testMeRejectsATamperedToken`, `testMeRejectsAnInvalidToken`, `testMeRequiresAToken` |
| mot de passe de compte haché, jamais renvoyé | `AuthControllerTest` : `testRegisterStoresAHashedPassword`, `testRegisterCreatesAUser` |
| mot de passe de fichier haché, jamais en clair en base | `FileControllerTest` : `testPasswordIsHashedInDatabase`, `testPlainPasswordIsNeverWrittenInTheDatabase` |
| propriété des fichiers (404 pour ceux des autres) | `FileControllerTest` : `testCannotDeleteSomeoneElsesFile`, `testListOnlyContainsMyFiles` |
| ticket impossible à falsifier, valable pour un seul fichier, temporaire | `DownloadTicketTest` (altération, prolongation, autre fichier, expiration, formats invalides) |
| fichier protégé inaccessible sans ticket valable | `SharedFileControllerTest` : `testProtectedFileCannotBeDownloadedWithoutATicket`, `testTicketOfAnotherFileIsRefused`, `testExpiredTicketIsRefused` |
| limite d'essais, blocage, remise à zéro | `SharedFileControllerTest` : `testTooManyWrongPasswordsLocksTheFile`, `testEvenTheRightPasswordIsRefusedWhileLocked`, `testASuccessfulAttemptResetsTheCounter` |
| expiration vérifiée à chaque appel | `SharedFileControllerTest` : `testValidTicketDoesNotOutliveTheFileExpiration`, `testDownloadAnExpiredFileGives410EvenIfItsContentIsStillOnDisk` |
| téléchargement jamais interprété par le navigateur | `SharedFileControllerTest` : `testDownloadIsNeverInterpretedByTheBrowserNorCachedByProxies` |
| nom de fichier piégé nettoyé, taille limitée, type détecté | `FileControllerTest` : `testPathInTheClientFileNameIsRemoved`, `testFileOverTheLimitIsRejected`, `testMimeTypeIsDetectedFromTheContentNotTheClaim` |
| aucune donnée interne dans les réponses publiques | `SharedFileControllerTest::testShowNeverExposesInternalOrSecretFields`, `FileControllerTest::testResponseNeverExposesInternalOrSecretFields` |
| routes protégées = routes documentées avec un cadenas | `ApiDocumentationTest::testDocumentedAuthenticationMatchesTheRealSecurity` |

Côté frontend (`npm test`) : les appels publics n'envoient pas de jeton et ne déconnectent pas sur un `401`
(`api.test.js`, `DownloadView.test.js`), les pages protégées renvoient un visiteur vers la connexion (`router.test.js`),
et la page de téléchargement affiche « mot de passe incorrect » ou « trop de tentatives » sans lancer de téléchargement
(`DownloadView.test.js`).

## 11. Signaler une faille

DataShare est un projet pédagogique, sans service en production. Pour signaler un problème de sécurité, ouvrez une
*issue* sur le dépôt en décrivant la faille et les étapes pour la reproduire, **sans y joindre de donnée personnelle ni de
secret réel**.
