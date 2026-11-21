# MAINTENANCE.md — Procédures de maintenance

Ce document dit **quoi faire, à quelle fréquence, comment, et quels sont les risques**. Les commandes sont à lancer
depuis la racine du dépôt. Les procédures marquées *(à tester)* sont décrites mais n'ont pas été rejouées sur un
serveur de production, puisqu'il n'y en a pas encore.

## 1. Calendrier récapitulatif

| Tâche | Fréquence | Responsable | Section |
|---|---|---|---|
| Purge des fichiers expirés | **toutes les heures** (automatique) | tâche planifiée | §2 |
| Surveillance de `/api/health` | en continu, ou toutes les minutes | supervision | §3 |
| Sauvegarde de la base **et** du dossier `backend/var/uploads/` | **chaque jour** | administrateur | §4 |
| Scan de sécurité des dépendances (`composer audit`, `npm audit`) | **chaque semaine**, et avant chaque livraison | développeur | §5 |
| Mises à jour mineures et correctifs des dépendances | **chaque mois** | développeur | §5 |
| Mise à jour d'une version majeure (Symfony, Vue, Vite, PHP) | à chaque sortie, **planifiée** (1 à 2 fois par an) | développeur | §6 |
| Vérification de l'espace disque | chaque semaine | administrateur | §7 |
| Rotation des clés JWT | **une fois par an**, ou aussitôt en cas de fuite | administrateur | §8 |
| Relecture de [SECURITY.md](SECURITY.md) (limites connues) | à chaque version | développeur | — |
| Restauration d'essai d'une sauvegarde | **chaque trimestre** | administrateur | §4 |

## 2. Purge des fichiers expirés

L'API refuse déjà un fichier expiré (410), mais **son contenu reste sur le disque** tant que la commande n'est pas lancée
(limite n°11 de [SECURITY.md](SECURITY.md)). La commande supprime le contenu des fichiers expirés. La ligne est conservée
pour un fichier appartenant à un utilisateur (il voit « Expiré » dans sa liste), et supprimée pour un envoi anonyme.

```bash
cd backend && php bin/console app:files:purge-expired
```

**À planifier toutes les heures.**

- Linux (`crontab -e`) :
  ```
  0 * * * * cd /chemin/datashare/backend && APP_ENV=prod php bin/console app:files:purge-expired >> var/log/purge.log 2>&1
  ```
- Windows (Planificateur de tâches, à lancer en PowerShell) *(à tester)* :
  ```powershell
  schtasks /Create /SC HOURLY /TN "DataShare purge" /TR "cmd /c cd /d C:\laragon\www\datashare\backend && php bin/console app:files:purge-expired"
  ```

**Vérifier** : la commande affiche « N fichier(s) expiré(s) supprimé(s) du disque ». Si la tâche s'arrête, rien ne casse :
les fichiers expirés restent simplement stockés plus longtemps, et le disque se remplit.

## 3. Surveillance

- `GET /api/health` répond `{"app":"datashare","status":"ok"}` quand l'API tourne. À interroger toutes les minutes
  (supervision, ou `curl -f`). Il ne teste **pas** la base : un échec de ce point = serveur ou PHP arrêté ; pour la
  base, vérifier aussi qu'un `GET /api/shared/<lien existant>` répond.
- **Journaux** : en production, Symfony écrit sur `stderr` (`config/packages/monolog.yaml`) : à rediriger vers le journal
  du serveur web ou de PHP-FPM. En développement : `backend/var/log/dev.log`.
- Surveiller les réponses `429` (limites déclenchées) et `500` (erreurs). Une hausse brutale de `429` sur la connexion
  indique une tentative de force brute.

## 4. Sauvegarde et restauration

Il y a **deux choses à sauvegarder ensemble** : la base PostgreSQL (comptes, liens, expirations) **et** le dossier
`backend/var/uploads/` (contenu des fichiers). Sauvegarder l'un sans l'autre donne des liens qui répondent 410 ou des
fichiers orphelins. Les clés JWT (`backend/config/jwt/`) et `backend/.env.local` se sauvegardent à part, **dans un
endroit sûr** (ils contiennent des secrets).

```bash
# Sauvegarde (quotidienne)
pg_dump -U postgres -Fc datashare > datashare-$(date +%F).dump
tar czf uploads-$(date +%F).tar.gz -C backend/var uploads

# Restauration (sur une base vide)
createdb -U postgres datashare
pg_restore -U postgres -d datashare datashare-2026-11-21.dump
tar xzf uploads-2026-11-21.tar.gz -C backend/var
```

Un export de la base de développement (`backup-datashare-2026-11-21.dump`) a déjà été fait et rechargé avec succès lors de
la migration vers le serveur PostgreSQL de Laragon. **Essai de restauration à refaire chaque trimestre** : une
sauvegarde jamais restaurée n'est pas une sauvegarde.

**Conservation conseillée** : 7 sauvegardes quotidiennes, 4 hebdomadaires. Les fichiers partagés étant supprimés après
7 jours au plus, les sauvegardes d'`uploads` perdent vite de l'intérêt : 7 jours suffisent.

## 5. Mettre à jour les dépendances (mineures et correctifs)

**Fréquence** : scan chaque semaine, mise à jour chaque mois, immédiate en cas d'alerte de sécurité.

```bash
# 1. Regarder
cd backend  && composer audit && composer outdated --direct
cd ../frontend && npm audit --omit=dev && npm outdated

# 2. Mettre à jour (versions autorisées par composer.json / package.json)
cd ../backend  && composer update
cd ../frontend && npm update

# 3. Vérifier
cd ../backend  && php vendor/bin/phpunit
cd ../frontend && npm test && npm run build

# 4. Si l'API a changé : régénérer la documentation
cd ../backend && composer docs
```

Règles :
1. **Une mise à jour à la fois**, ou un groupe cohérent (par exemple Doctrine ORM + DoctrineBundle), pour savoir quoi
   annuler en cas de problème.
2. **Ne jamais mettre à jour sans tests au vert avant, et après.** Les tests couvrent environ 95 % du backend et 100 %
   des lignes du frontend ([TESTING.md](TESTING.md)) : c'est le filet de sécurité.
3. Commiter `composer.lock` et `package-lock.json` avec la mise à jour (installations reproductibles).
4. Lire le *changelog* des paquets sensibles : `lexik/jwt-authentication-bundle`, `symfony/security-bundle`,
   `nelmio/api-doc-bundle` (la spécification OpenAPI est testée : `ApiDocumentationTest` échoue si elle diverge).
5. Une alerte `npm audit` n'oblige pas à tout mettre à jour : on regarde si le paquet est dans le code **envoyé en
   production** (`--omit=dev`) ou seulement dans les outils. Voir l'analyse dans [SECURITY.md](SECURITY.md) §8.
   Ne **jamais** lancer `npm audit fix --force` sans lire ce qu'il change (il propose parfois un retour en arrière).

## 6. Mettre à jour une version majeure

Concerne Symfony (8.1 aujourd'hui, une version mineure tous les 6 mois), PHP (≥ 8.4), Vue, Vite, PostgreSQL (≥ 16).

1. Créer une **branche** dédiée, jamais travailler directement sur la branche principale.
2. Lire le guide de migration officiel (`UPGRADE-*.md` de Symfony, notes de version de Vite).
3. Mettre à jour, corriger les avertissements de dépréciation, lancer **tous** les tests, puis tester à la main un
   parcours complet : inscription, connexion, envoi, lien, mot de passe, téléchargement, suppression.
4. Prévoir un **retour arrière** : sauvegarde (§4) et ancien `composer.lock` / `package-lock.json` sous git.
5. Mettre à jour les prérequis dans [README.md](README.md) et les scripts d'installation si une version minimale change.

## 7. Base de données, migrations et espace disque

**Appliquer une migration** après le déploiement d'une nouvelle version :

```bash
cd backend && php bin/console doctrine:migrations:migrate --no-interaction
```

- Deux migrations existent aujourd'hui (`backend/migrations/`). Toute modification d'entité doit s'accompagner d'une
  migration commitée (`php bin/console make:migration`), relue avant d'être appliquée.
- **Sauvegarder avant de migrer.** Une migration qui supprime une colonne est irréversible sans sauvegarde.
- Ne pas modifier une migration déjà appliquée ailleurs : en créer une nouvelle.
- Pour la base de test : `php bin/console doctrine:migrations:migrate --env=test --no-interaction`.

**Espace disque** : les fichiers pèsent jusqu'à 1 Go et vivent au plus 7 jours. Il n'y a **pas de plafond total**
(limite n°2 de [SECURITY.md](SECURITY.md)) : surveiller `backend/var/uploads/` chaque semaine, et si le disque se remplit,
vérifier d'abord que la purge (§2) tourne.

## 8. Secrets et clés JWT

| Secret | Où | Rotation |
|---|---|---|
| `APP_SECRET` | `backend/.env.local` | à chaque fuite supposée |
| `JWT_PASSPHRASE`, clés `private.pem` / `public.pem` | `backend/.env.local`, `backend/config/jwt/` | **une fois par an**, ou aussitôt en cas de fuite |
| Mot de passe de la base | `DATABASE_URL` dans `backend/.env.local` | une fois par an |

**Rotation des clés JWT** :

```powershell
.\scripts\install.ps1 -Force      # régénère backend/.env.local ET les clés (voir README)
```
ou à la main : `php bin/console lexik:jwt:generate-keypair --overwrite`, puis mettre la nouvelle `JWT_PASSPHRASE`.

**Conséquence** : tous les jetons existants deviennent invalides, donc **tous les utilisateurs doivent se reconnecter**
(le site les renvoie vers `/login`). Prévoir l'opération à un moment calme. Aucun fichier ni compte n'est perdu.

## 9. Déployer une nouvelle version *(à tester)*

1. Sauvegarde (§4).
2. `git pull`, puis `cd backend && composer install --no-dev --optimize-autoloader`, `cd ../frontend && npm ci && npm run build`.
3. Migrations (§7), puis `APP_ENV=prod php bin/console cache:clear`.
4. Servir `frontend/dist/` avec le serveur web, et l'API depuis `backend/public/`.
5. Vérifier `/api/health`, puis un parcours complet.
6. **En cas d'échec** : revenir à la version précédente de git, restaurer la base si une migration a été appliquée (§4).

## 10. Risques et parades

| Risque | Gravité | Parade |
|---|---|---|
| Disque plein (envois massifs, purge arrêtée) | élevée : plus aucun envoi possible | surveiller `var/uploads/`, purge horaire, limite de 10 envois/heure par IP pour les anonymes, piste : quotas |
| Perte de la base ou des fichiers | élevée | sauvegardes quotidiennes des deux, essai de restauration trimestriel |
| Mise à jour qui casse l'application | moyenne | tests avant / après, branche dédiée, `composer.lock` et `package-lock.json` commités, retour arrière prévu |
| Faille dans une dépendance | moyenne | `composer audit` / `npm audit` chaque semaine, correctifs mensuels |
| Fuite des clés JWT ou de `APP_SECRET` | élevée : jetons falsifiables | clés hors de git, rotation annuelle et immédiate si fuite |
| Application déployée en mode `dev` | élevée : détails techniques exposés | toujours `APP_ENV=prod APP_DEBUG=0` |
| Tâche de purge arrêtée sans qu'on le voie | faible à moyenne | consigner la sortie dans un journal, alerte si aucune ligne depuis 2 heures |
| Limite d'essais fausse derrière un proxy | faible | configurer `trusted_proxies` au déploiement (limite n°6 de [SECURITY.md](SECURITY.md)) |
| HTTPS absent | élevée : mots de passe et jetons en clair | terminaison TLS obligatoire en production (limite n°5) |

## 11. Notes pour la personne qui reprend le projet

- Toute la configuration locale est dans `backend/.env.local` : **on ne modifie jamais `backend/.env`**.
- Après un changement de l'API (route, champ, code de réponse) : `cd backend && composer docs`, puis commiter
  `docs/openapi.yaml` ; un test échoue sinon.
- L'installation complète sur une machine neuve se fait avec `scripts/install.ps1` / `scripts/install.sh`
  (voir [README.md](README.md)) ; ils sont rejouables.
- Les mesures de performance se refont avec `scripts/perf/load-test.mjs` (voir [PERF.md](PERF.md)).
