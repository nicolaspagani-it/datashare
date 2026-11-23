#!/usr/bin/env bash
# ============================================================================
# Installe et configure DataShare : backend Symfony, base PostgreSQL et frontend Vue.
# (Équivalent Linux / macOS de scripts/install.ps1.)
#
# Le script est rejouable : on peut le relancer sans rien casser (il ne remplace pas un
# backend/.env.local existant, sauf avec --force). Il ne modifie jamais backend/.env.
#
# Usage :
#   ./scripts/install.sh [--force] [--skip-frontend] [--run-tests]
#
# Configuration, par variables d'environnement (valeurs par défaut entre parenthèses) :
#   DB_HOST (127.0.0.1)   DB_PORT (5432)   DB_USER (postgres)   DB_PASSWORD (aucun)
#   DB_NAME (datashare)   FRONT_URL (http://localhost:5173)
#
# Exemple :
#   DB_USER=postgres DB_PASSWORD='mon-mot-de-passe' ./scripts/install.sh --run-tests
# ============================================================================

set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-postgres}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_NAME="${DB_NAME:-datashare}"
FRONT_URL="${FRONT_URL:-http://localhost:5173}"

FORCE=0
SKIP_FRONTEND=0
RUN_TESTS=0
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    --skip-frontend) SKIP_FRONTEND=1 ;;
    --run-tests) RUN_TESTS=1 ;;
    -h | --help)
      sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Option inconnue : $arg (voir --help)" >&2
      exit 1
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SCRIPT_DIR")"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

# --- Petits outils ------------------------------------------------------------

step() { printf '\n\033[36m==> %s\033[0m\n' "$1"; }
ok() { printf '\033[32m    OK  %s\033[0m\n' "$1"; }
note() { printf '\033[33m    --  %s\033[0m\n' "$1"; }
fail() {
  printf '\n\033[31mERREUR : %s\033[0m\n' "$1" >&2
  exit 1
}

# Valeur d'une variable dans un fichier .env (sans les guillemets), vide si absente.
env_value() { grep -E "^$2=" "$1" | head -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

# --- 1. Prérequis -------------------------------------------------------------

step 'Vérification des prérequis'

command -v php >/dev/null 2>&1 || fail 'php est introuvable dans le PATH. Installe PHP 8.4 : https://www.php.net/downloads'
command -v composer >/dev/null 2>&1 || fail 'composer est introuvable. Installe-le : https://getcomposer.org/download/'
command -v node >/dev/null 2>&1 || fail 'node est introuvable. Installe Node.js 20.19+ ou 22.12+ : https://nodejs.org/'
command -v npm >/dev/null 2>&1 || fail 'npm est introuvable (il est livré avec Node.js).'

PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || fail "PHP $PHP_VERSION trouvé, mais PHP 8.4 ou plus est requis."
ok "PHP $PHP_VERSION"

MISSING=""
for ext in ctype iconv mbstring openssl sodium pdo_pgsql fileinfo; do
  php -m | tr -d '\r' | tr '[:upper:]' '[:lower:]' | grep -qx "$ext" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || fail "Extensions PHP manquantes :$MISSING. Installe-les (ex. Debian/Ubuntu : apt install php-pgsql php-mbstring ...) ou active-les dans php.ini."
ok 'extensions PHP : ctype, iconv, mbstring, openssl, sodium, pdo_pgsql, fileinfo'

NODE_VERSION="$(node -v | sed 's/^v//')"
node -e '
  const [major, minor] = process.versions.node.split(".").map(Number);
  const ok = (major === 20 && minor >= 19) || (major === 22 && minor >= 12) || major > 22;
  process.exit(ok ? 0 : 1);
' || fail "Node.js $NODE_VERSION trouvé, mais la version 20.19+ ou 22.12+ est requise (exigence de Vite)."
ok "Node.js $NODE_VERSION, npm $(npm -v)"

SERVER_MAJOR="$(DB_PASSWORD="$DB_PASSWORD" php "$SCRIPT_DIR/db/check-connection.php" "$DB_HOST" "$DB_PORT" "$DB_USER" 2>&1)" ||
  fail "Impossible de se connecter à PostgreSQL ($DB_USER@$DB_HOST:$DB_PORT) : $SERVER_MAJOR
    Vérifie que le serveur est démarré, puis relance avec DB_USER / DB_PASSWORD / DB_HOST / DB_PORT.
    Pas encore d'utilisateur ? Un administrateur peut le créer avec scripts/db/init.sql (voir le README)."
ok "PostgreSQL $SERVER_MAJOR ($DB_USER@$DB_HOST:$DB_PORT)"

# Si 'npm run dev' tourne encore dans frontend/, remplacer node_modules en dessous casserait ce serveur,
# et sous Windows (Git Bash) les fichiers verrouillés feraient échouer 'npm ci' à mi-chemin.
# On vérifie donc AVANT de modifier quoi que ce soit. (ps -axo : Linux et macOS ; absent ailleurs : on ignore.)
if [ "$SKIP_FRONTEND" -eq 0 ]; then
  BUSY="$( (ps -axo pid=,args= 2>/dev/null || true) | grep -F "$FRONTEND/node_modules" | grep -v 'grep -F' || true)"
  if [ -n "$BUSY" ]; then
    fail "Des programmes Node.js utilisent encore le dossier frontend (souvent 'npm run dev') :
$BUSY
    Arrête-les (Ctrl+C dans leur terminal, ou : kill <PID>), puis relance le script.
    Sinon l'installation du frontend resterait à moitié faite. (--skip-frontend ignore le frontend.)"
  fi
  ok 'aucun serveur de développement ne bloque le frontend'
fi

# --- 2. Backend : dépendances -------------------------------------------------

step 'Backend : installation des dépendances (composer install)'
(cd "$BACKEND" && composer install --no-interaction --prefer-dist)
ok 'dépendances installées'

# --- 3. Backend : configuration locale ----------------------------------------

step 'Backend : configuration locale (.env.local, .env.test.local)'

ENV_LOCAL="$BACKEND/.env.local"
ENV_CREATED=0 # vaut 1 si on (re)génère .env.local : il faut alors régénérer aussi les clés JWT
if [ -f "$ENV_LOCAL" ] && [ "$FORCE" -eq 0 ]; then
  note '.env.local existe déjà : conservé tel quel (--force pour le régénérer)'
else
  # rawurlencode : le mot de passe peut contenir des caractères spéciaux (@ : / ? # %)
  USER_PART="$(DB_VALUE="$DB_USER" php -r 'echo rawurlencode(getenv("DB_VALUE"));')"
  if [ -n "$DB_PASSWORD" ]; then
    USER_PART="$USER_PART:$(DB_VALUE="$DB_PASSWORD" php -r 'echo rawurlencode(getenv("DB_VALUE"));')"
  fi
  # Symfony lit DATABASE_URL avec un traitement qui interprète "%...%" comme un paramètre : chaque "%"
  # produit par l'encodage (ex : %40 pour @) doit être doublé ("%%40"), sinon "non-existent parameter".
  USER_PART="$(printf '%s' "$USER_PART" | sed 's/%/%%/g')"
  cat >"$ENV_LOCAL" <<EOF
# Généré par scripts/install.sh : configuration locale, ignorée par git (ne jamais la commiter).
APP_ENV=dev
APP_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
DATABASE_URL="postgresql://$USER_PART@$DB_HOST:$DB_PORT/$DB_NAME?serverVersion=$SERVER_MAJOR&charset=utf8"
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=$(php -r 'echo bin2hex(random_bytes(32));')
FRONT_URL=$FRONT_URL
EOF
  ENV_CREATED=1
  ok '.env.local créé'
fi

# En mode test, Symfony ne lit pas .env.local : on recopie dans .env.test.local ce dont les tests ont besoin.
DATABASE_URL="$(env_value "$ENV_LOCAL" DATABASE_URL)"
JWT_PASSPHRASE="$(env_value "$ENV_LOCAL" JWT_PASSPHRASE)"
{ [ -n "$DATABASE_URL" ] && [ -n "$JWT_PASSPHRASE" ]; } ||
  fail 'DATABASE_URL ou JWT_PASSPHRASE est absent de backend/.env.local. Corrige-le, ou relance avec --force.'
cat >"$BACKEND/.env.test.local" <<EOF
# Généré par scripts/install.sh : configuration des tests (la base utilisée est "<nom>_test").
DATABASE_URL="$DATABASE_URL"
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=$JWT_PASSPHRASE
EOF
ok '.env.test.local créé'

# --- 4. Clés JWT --------------------------------------------------------------

step 'Backend : clés de signature JWT'

# OpenSSL (utilisé par PHP pour fabriquer les clés) lit le fichier désigné par la variable d'environnement
# OPENSSL_CONF. Si elle pointe vers un fichier qui n'existe pas, la génération échoue avec
# « error:80000003:system library: No such process ». On la corrige alors, pour cette exécution seulement,
# avec un fichier de configuration minimal.
#
# On ne se contente pas de deviner : on TESTE si PHP sait créer une clé RSA. Si non, on essaie avec une
# configuration OpenSSL minimale ; si ça échoue encore, on affiche un diagnostic précis.
PROBE_FILE="$(mktemp)"
cat > "$PROBE_FILE" <<'PHP'
<?php
$key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    while ($e = openssl_error_string()) { echo $e, "\n"; }
    exit(1);
}
echo "ok\n";
PHP
if ! php "$PROBE_FILE" >/dev/null 2>&1; then
  MINIMAL_CONF="$(mktemp)"
  printf 'openssl_conf = default_conf\n[default_conf]\n[req]\ndistinguished_name = dn\n[dn]\n' > "$MINIMAL_CONF"
  note "PHP ne sait pas créer de clé avec la configuration OpenSSL actuelle (OPENSSL_CONF='${OPENSSL_CONF:-}') : essai avec une configuration minimale"
  export OPENSSL_CONF="$MINIMAL_CONF"
  if ! PROBE_OUT="$(php "$PROBE_FILE" 2>&1)"; then
    fail "Impossible de créer une clé RSA avec PHP, même avec une configuration OpenSSL minimale.
Erreurs OpenSSL :
$PROBE_OUT
Configuration PHP :
$(php --ini)
Vérifie que l'extension openssl de PHP est chargée (php -m)."
  fi
  ok 'configuration OpenSSL minimale utilisée pour cette installation'
fi
rm -f "$PROBE_FILE"

# La clé privée est chiffrée avec JWT_PASSPHRASE : si .env.local vient d'être (re)créé, la phrase secrète
# est nouvelle et les anciennes clés ne s'ouvriraient plus (la connexion échouerait). On les régénère donc.
if [ "$ENV_CREATED" -eq 1 ]; then
  (cd "$BACKEND" && php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction)
  ok 'clés régénérées dans backend/config/jwt/ (en accord avec le nouveau .env.local)'
else
  (cd "$BACKEND" && php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction)
  ok 'clés présentes dans backend/config/jwt/'
fi

# --- 5. Base de données -------------------------------------------------------

step 'Base de données : création et migrations'
for environment in dev test; do
  (cd "$BACKEND" &&
    php bin/console doctrine:database:create --if-not-exists --no-interaction --env="$environment" &&
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="$environment")
  ok "environnement '$environment' : base prête, migrations appliquées"
done

# --- 6. Frontend --------------------------------------------------------------

if [ "$SKIP_FRONTEND" -eq 1 ]; then
  note 'Frontend ignoré (--skip-frontend)'
else
  step 'Frontend : installation des dépendances (npm ci)'
  (cd "$FRONTEND" && npm ci --no-audit --no-fund)
  # Contrôle : le lanceur de Vite doit exister, sinon 'npm run dev' répondrait "vite: command not found".
  [ -e "$FRONTEND/node_modules/.bin/vite" ] ||
    fail "L'installation du frontend est incomplète (frontend/node_modules/.bin/vite introuvable). Supprime le dossier frontend/node_modules et relance le script."
  ok 'dépendances installées'
fi

# --- 7. Tests (facultatif) ----------------------------------------------------

if [ "$RUN_TESTS" -eq 1 ]; then
  step 'Tests'
  (cd "$BACKEND" && php vendor/bin/phpunit)
  if [ "$SKIP_FRONTEND" -eq 0 ]; then (cd "$FRONTEND" && npm test); fi
  ok 'tous les tests passent'
fi

# --- Fin ----------------------------------------------------------------------

printf '\n\033[32mInstallation terminée.\033[0m\n'
cat <<'EOF'

Pour lancer l'application (2 terminaux) :
  1. backend  : cd backend && php -S 127.0.0.1:8000 -t public      (ou : symfony serve --no-tls)
  2. frontend : cd frontend && npm run dev
Puis ouvre http://localhost:5173 et crée un compte.
La documentation de l'API (Swagger) est sur http://127.0.0.1:8000/docs/ quand le backend tourne.

À planifier (supprime du disque les fichiers expirés), par exemple toutes les heures :
  cd backend && php bin/console app:files:purge-expired
EOF
