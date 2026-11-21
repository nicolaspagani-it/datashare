# AI.md — Usage de l'IA dans le projet

## 1. En bref

DataShare a été développé **avec l'assistant de programmation Claude Code** (modèles Claude d'Anthropic), utilisé dans
l'application de bureau Claude, sur le poste du développeur. L'IA a écrit une grande partie du code, des tests et de la
documentation ; **le développeur a cadré chaque étape, relu, lancé et validé le résultat**, et pris les décisions de
conception. Rien n'a été livré sans être exécuté.

## 2. Outils utilisés

| Outil | Usage |
|---|---|
| **Claude Code** (Claude Sonnet) | écriture et modification du code (Symfony, Vue), des tests, des scripts et des documents ; exécution des commandes (`composer`, `npm`, `phpunit`, `vitest`, PostgreSQL) ; diagnostic des erreurs |
| **Connecteur Figma** | lecture des maquettes (accueil, téléversement, téléchargement, « Mon espace ») pour reproduire le design. Le quota du connecteur s'est épuisé en cours de route : des captures d'écran fournies par le développeur ont pris le relais |
| **Navigateur intégré** | vérification des pages et mesures de performance du frontend |
| **Revue de code assistée** | une passe générale de revue (bugs, sécurité, cohérence) à la fin de la phase de développement |

## 3. Qui a fait quoi

| Décision ou tâche | Auteur |
|---|---|
| Sujet, fonctionnalités, durées de conservation (1 à 7 jours), limite de 1 Go, mot de passe facultatif haché | **développeur** |
| Choix de Symfony + Vue + PostgreSQL, du JWT, de la suppression de Twig (le front est entièrement en Vue), d'Adminer de Laragon | **développeur**, après explications de l'IA |
| Maquettes (Figma) | **développeur** |
| Découpage en étapes (« on y va étape par étape, ne fais rien pour le moment ») | **développeur** |
| Code de l'API, des vues, des composants, des scripts d'installation | **IA**, relu et exécuté par le développeur |
| Tests unitaires, d'intégration et de couverture | **IA** ; le développeur a lancé les tests dans VS Code et demandé la couverture |
| Documentation (README, SECURITY, TESTING, PERF, MAINTENANCE, OpenAPI) | **IA**, à partir du code réel et de mesures réelles |
| Commits | **développeur** : le message est proposé par l'IA, le développeur commite |

Les commits dont l'essentiel a été écrit par l'IA portent le type `feat(ai)` dans l'historique git (par exemple
l'implémentation du service de téléchargement), pour que l'origine soit visible.

## 4. Méthode de travail

1. **Une étape à la fois.** Le développeur décrit le besoin, puis l'IA propose ou implémente. Pour les sujets
   structurants (authentification, téléchargement, documentation, installation), l'IA a d'abord **expliqué et proposé un
   plan**, sans toucher au code, jusqu'au feu vert.
2. **Code commenté en français** : le développeur connaît bien Symfony mais pas Vue ; l'IA a donc expliqué le frontend
   en comparant avec Symfony (store Pinia = service, routeur = `routes.yaml` + `security.yaml`…).
3. **Exécution systématique.** Après chaque modification, l'IA lance les tests et, pour le comportement réel,
   interroge l'API qui tourne (`curl`, navigateur intégré).
4. **Règle fixée par le développeur** : ne jamais modifier `backend/.env`, tout va dans `backend/.env.local`. L'IA l'a
   enregistrée et appliquée (une recette Composer l'avait modifié : il a été restauré).

## 5. Comment le travail de l'IA est contrôlé

| Contrôle | Résultat |
|---|---|
| Tests automatisés | 198 PHPUnit et 251 Vitest, couverture 95 % (backend) et 100 % des lignes (frontend) : [TESTING.md](TESTING.md) |
| Tests de cohérence de la documentation | `ApiDocumentationTest` échoue si la spécification OpenAPI s'écarte du code |
| Installation sur copie vierge | script rejoué sur une copie propre du dépôt (Windows 11) |
| Scan des dépendances | `composer audit`, `npm audit` : [SECURITY.md](SECURITY.md) §8 |
| **Revue de code indépendante** (passe générale demandée par le développeur) | 8 constats, dont 4 importants, tous traités sauf 2 mineurs (voir ci-dessous) |
| Sondes manuelles sur l'API réelle | 20 connexions erronées d'affilée acceptées, jeton invalide, nom de fichier en UTF-8 invalide |

### Ce que la revue a trouvé dans le code écrit par l'IA

Du code généré **n'est pas fiable par défaut** ; voici ce qu'une relecture ciblée a révélé et fait corriger :

- aucune limite d'essais sur la connexion (force brute) → `login_throttling` ;
- envoi public sans limite de débit (risque de remplir le disque) → 10 envois par heure et par IP ;
- un jeton JWT expiré faisait perdre le fichier choisi à l'envoi → nouvel essai en visiteur ;
- emails sensibles à la casse (deux comptes pour une même adresse) → emails normalisés en minuscules ;
- un test qui passait grâce à une erreur simulée que l'API ne renvoie jamais → test rendu réaliste.

Deux constats mineurs ont été laissés ouverts volontairement et sont documentés (`trusted_proxies`, course entre
purge et téléchargement).

### Erreurs de l'IA rencontrées en cours de route

| Erreur | Correction |
|---|---|
| Réécriture d'un fichier binaire (Adminer) en UTF-8 qui l'a corrompu | fichier officiel retéléchargé, modifié octet par octet |
| Encodage PowerShell : BOM ajouté à un fichier `.env` | réécrit sans BOM ; BOM ajouté seulement aux scripts `.ps1` |
| Recettes Composer modifiant `.env` | restauré, valeurs déplacées dans `.env.local` |
| Test qui renvoyait un mock (traité comme fonction de nettoyage) | accolades ajoutées |
| Documentation OpenAPI : schémas en doublon, virgules YAML non guillemetées | corrigés, et protégés par des tests |
| Conflit entre deux serveurs PostgreSQL | données migrées vers celui de Laragon, l'autre supprimé après accord |

## 6. Limites et risques assumés

- **Fiabilité** : l'IA peut écrire du code qui semble juste mais ne l'est pas. Parades : tests, exécution réelle,
  revue séparée. Elle ne remplace pas la relecture humaine.
- **Sécurité** : le code a été revu pour les injections SQL (requêtes Doctrine paramétrées), le XSS (aucun `v-html`),
  le CSRF (jeton en en-tête, sans cookie), les accès aux fichiers d'autrui et le détournement de chemins. Il n'a
  **pas** fait l'objet d'un test d'intrusion indépendant. Limites connues : [SECURITY.md](SECURITY.md) §9.
- **Confidentialité** : les échanges avec l'IA transitent par le service d'Anthropic. Aucun secret réel n'a été
  placé dans git ; les secrets restent dans `backend/.env.local` (ignoré par git). Les fichiers de test étaient des
  contenus fictifs.
- **Compréhension** : un code que l'on n'a pas écrit se comprend moins bien. D'où les commentaires en français,
  les explications données au fil de l'eau, et les documents du dépôt.
- **Propriété et licences** : le code généré est de la responsabilité du développeur qui le livre ; les dépendances
  sont celles de Symfony, Vue et leurs écosystèmes (analysées par `composer audit` et `npm audit`).
- **Dépendance à des outils** : le quota du connecteur Figma s'est épuisé en cours de projet ; le projet ne dépend
  pourtant d'aucun outil d'IA pour fonctionner ou être maintenu (tout est dans le dépôt).

## 7. Ce que l'IA a apporté

- **Vitesse** : une application complète (API, interface, tests, scripts, documentation) livrée par étapes courtes.
- **Qualité de départ** : tests écrits en même temps que le code, documentation de l'API générée et vérifiée.
- **Apprentissage** : explications du frontend Vue à partir de concepts Symfony déjà connus (store, routeur, gardes).
- **Diagnostic** : analyse rapide d'erreurs complexes (deux serveurs PostgreSQL, verrous Windows de `npm`, extension
  `sodium` manquante pour Composer).
