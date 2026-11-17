<#
.SYNOPSIS
    Installe et configure DataShare : backend Symfony, base PostgreSQL et frontend Vue.

.DESCRIPTION
    Le script est rejouable : on peut le relancer sans rien casser (il ne remplace pas un
    .env.local existant, sauf avec -Force). Il :
      1. vérifie les prérequis (PHP 8.4 et ses extensions, Composer, Node.js, accès à PostgreSQL)
      2. installe les dépendances du backend (composer install)
      3. crée backend/.env.local (base de données, secret, clés JWT) et backend/.env.test.local
      4. génère les clés JWT
      5. crée les bases (développement et test) et applique les migrations
      6. installe les dépendances du frontend (npm ci)
    Il ne modifie jamais backend/.env.

.PARAMETER DbHost      Hôte PostgreSQL (défaut : 127.0.0.1). Peut aussi se donner par $env:DB_HOST.
.PARAMETER DbPort      Port PostgreSQL (défaut : 5432). Variable : $env:DB_PORT.
.PARAMETER DbUser      Utilisateur PostgreSQL, avec le droit de créer une base (défaut : postgres). Variable : $env:DB_USER.
.PARAMETER DbPassword  Mot de passe de cet utilisateur (défaut : aucun). Variable : $env:DB_PASSWORD.
.PARAMETER DbName      Nom de la base de l'application (défaut : datashare). La base de test s'appellera <nom>_test.
.PARAMETER FrontUrl    Adresse du frontend, pour les liens de partage (défaut : http://localhost:5173).
.PARAMETER Force       Régénère backend/.env.local même s'il existe déjà.
.PARAMETER SkipFrontend Ne pas installer le frontend.
.PARAMETER RunTests    Lance les tests (PHPUnit et Vitest) à la fin de l'installation.

.EXAMPLE
    .\scripts\install.ps1 -DbUser postgres -DbPassword 'mon-mot-de-passe'

.EXAMPLE
    .\scripts\install.ps1 -DbUser root -DbName datashare -RunTests
#>
[CmdletBinding()]
param(
    [string]$DbHost = $(if ($env:DB_HOST) { $env:DB_HOST } else { '127.0.0.1' }),
    [int]$DbPort = $(if ($env:DB_PORT) { [int]$env:DB_PORT } else { 5432 }),
    [string]$DbUser = $(if ($env:DB_USER) { $env:DB_USER } else { 'postgres' }),
    [string]$DbPassword = $(if ($env:DB_PASSWORD) { $env:DB_PASSWORD } else { '' }),
    [string]$DbName = $(if ($env:DB_NAME) { $env:DB_NAME } else { 'datashare' }),
    [string]$FrontUrl = $(if ($env:FRONT_URL) { $env:FRONT_URL } else { 'http://localhost:5173' }),
    [switch]$Force,
    [switch]$SkipFrontend,
    [switch]$RunTests
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'

# --- Petits outils ------------------------------------------------------------------------------

function Write-Step([string]$Message) { Write-Host "`n==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message) { Write-Host "    OK  $Message" -ForegroundColor Green }
function Write-Note([string]$Message) { Write-Host "    --  $Message" -ForegroundColor Yellow }

function Fail([string]$Message) {
    Write-Host "`nERREUR : $Message" -ForegroundColor Red
    exit 1
}

# Lance une commande dans un dossier et s'arrête si elle échoue (PowerShell ne le fait pas tout seul
# pour les programmes externes : il faut lire le code de sortie).
function Invoke-Native([string]$WorkDir, [string]$Program, [string[]]$Arguments) {
    Push-Location $WorkDir
    try {
        & $Program @Arguments
        if ($LASTEXITCODE -ne 0) { Fail "La commande a échoué (code $LASTEXITCODE) : $Program $($Arguments -join ' ')" }
    } finally {
        Pop-Location
    }
}

# Écrit un fichier en UTF-8 SANS BOM : Symfony refuse de lire un .env qui commence par un BOM.
function Write-Utf8NoBom([string]$Path, [string]$Content) {
    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding($false)))
}

# Une valeur aléatoire cryptographiquement sûre, en hexadécimal (2 caractères par octet).
function New-Secret([int]$Bytes = 32) {
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return -join ($buffer | ForEach-Object { $_.ToString('x2') })
}

# Lit la valeur d'une variable dans un fichier .env (sans les guillemets), ou $null si absente.
function Get-EnvValue([string]$File, [string]$Key) {
    $line = Get-Content $File | Where-Object { $_ -match "^$Key=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -replace "^$Key=", '').Trim().Trim('"')
}

# --- 1. Prérequis -------------------------------------------------------------------------------

Write-Step 'Vérification des prérequis'

foreach ($tool in @(
        @{ Name = 'php'; Hint = 'PHP 8.4 : https://www.php.net/downloads (ou Laragon)' },
        @{ Name = 'composer'; Hint = 'Composer : https://getcomposer.org/download/' },
        @{ Name = 'node'; Hint = 'Node.js 20.19+ ou 22.12+ : https://nodejs.org/' },
        @{ Name = 'npm'; Hint = 'npm est livré avec Node.js : https://nodejs.org/' })) {
    if (-not (Get-Command $tool.Name -ErrorAction SilentlyContinue)) {
        Fail "$($tool.Name) est introuvable dans le PATH. Installe-le : $($tool.Hint)"
    }
}

$phpVersion = (& php -r 'echo PHP_VERSION;')
if ([version]($phpVersion -replace '^(\d+\.\d+(\.\d+)?).*', '$1') -lt [version]'8.4') {
    Fail "PHP $phpVersion trouvé, mais PHP 8.4 ou plus est requis."
}
Write-Ok "PHP $phpVersion"

$loadedExtensions = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
$missing = @('ctype', 'iconv', 'mbstring', 'openssl', 'sodium', 'pdo_pgsql', 'fileinfo') | Where-Object { $loadedExtensions -notcontains $_ }
if ($missing) {
    Fail ("Extensions PHP manquantes : $($missing -join ', '). Active-les dans php.ini (ex : 'extension=pdo_pgsql' : retire le ';' devant), " +
        "puis relance. Fichier php.ini utilisé : $((& php --ini | Select-String 'Loaded Configuration File').ToString().Split(':',2)[1].Trim())")
}
Write-Ok 'extensions PHP : ctype, iconv, mbstring, openssl, sodium, pdo_pgsql, fileinfo'

$nodeVersion = [version]((& node -v).TrimStart('v'))
if (-not (($nodeVersion.Major -eq 20 -and $nodeVersion.Minor -ge 19) -or ($nodeVersion.Major -eq 22 -and $nodeVersion.Minor -ge 12) -or $nodeVersion.Major -gt 22)) {
    Fail "Node.js $nodeVersion trouvé, mais la version 20.19+ ou 22.12+ est requise (exigence de Vite)."
}
Write-Ok "Node.js $nodeVersion, npm $(& npm -v)"

$env:DB_PASSWORD = $DbPassword
# Avec $ErrorActionPreference = 'Stop', ce que PHP écrit sur sa sortie d'erreur ferait planter le script avec
# un message PowerShell illisible : on le désactive le temps de l'appel pour afficher NOTRE message.
$previousPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$serverMajor = (& php (Join-Path $PSScriptRoot 'db\check-connection.php') $DbHost $DbPort $DbUser 2>&1 | ForEach-Object { "$_" }) -join "`n"
$connectionExitCode = $LASTEXITCODE
$ErrorActionPreference = $previousPreference
if ($connectionExitCode -ne 0) {
    Fail ("Impossible de se connecter à PostgreSQL ($DbUser@${DbHost}:$DbPort) : $serverMajor`n" +
        "    Vérifie que le serveur est démarré, puis relance avec -DbUser / -DbPassword / -DbHost / -DbPort.`n" +
        "    Pas encore d'utilisateur ? Un administrateur peut le créer avec scripts\db\init.sql (voir le README).")
}
$serverMajor = [int]"$serverMajor".Trim()
Write-Ok "PostgreSQL $serverMajor ($DbUser@${DbHost}:$DbPort)"

# --- 2. Backend : dépendances -------------------------------------------------------------------

Write-Step 'Backend : installation des dépendances (composer install)'
Invoke-Native $Backend 'composer' @('install', '--no-interaction', '--prefer-dist')
Write-Ok 'dépendances installées'

# --- 3. Backend : configuration locale ----------------------------------------------------------

Write-Step 'Backend : configuration locale (.env.local, .env.test.local)'

$envLocal = Join-Path $Backend '.env.local'
$envCreated = $false   # vrai si on (re)génère .env.local : il faut alors régénérer aussi les clés JWT
if ((Test-Path $envLocal) -and -not $Force) {
    Write-Note '.env.local existe déjà : conservé tel quel (-Force pour le régénérer)'
} else {
    $userPart = [uri]::EscapeDataString($DbUser)
    if ($DbPassword -ne '') { $userPart += ':' + [uri]::EscapeDataString($DbPassword) }
    # Symfony lit DATABASE_URL avec un traitement qui interprète "%...%" comme un paramètre : chaque "%"
    # produit par l'encodage (ex : %40 pour @) doit être doublé ("%%40"), sinon "non-existent parameter".
    $userPart = $userPart -replace '%', '%%'
    $databaseUrl = "postgresql://$userPart@${DbHost}:$DbPort/${DbName}?serverVersion=$serverMajor&charset=utf8"

    Write-Utf8NoBom $envLocal (@"
# Généré par scripts/install.ps1 : configuration locale, ignorée par git (ne jamais la commiter).
APP_ENV=dev
APP_SECRET=$(New-Secret 32)
DATABASE_URL="$databaseUrl"
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=$(New-Secret 32)
FRONT_URL=$FrontUrl
"@ + "`n")
    $envCreated = $true
    Write-Ok '.env.local créé'
}

# En mode test, Symfony ne lit pas .env.local : on recopie dans .env.test.local ce dont les tests ont besoin.
$databaseUrl = Get-EnvValue $envLocal 'DATABASE_URL'
$jwtPassphrase = Get-EnvValue $envLocal 'JWT_PASSPHRASE'
if (-not $databaseUrl -or -not $jwtPassphrase) {
    Fail "DATABASE_URL ou JWT_PASSPHRASE est absent de backend\.env.local. Corrige-le, ou relance avec -Force."
}
Write-Utf8NoBom (Join-Path $Backend '.env.test.local') (@"
# Généré par scripts/install.ps1 : configuration des tests (la base utilisée est "<nom>_test").
DATABASE_URL="$databaseUrl"
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=$jwtPassphrase
"@ + "`n")
Write-Ok '.env.test.local créé'

# --- 4. Clés JWT --------------------------------------------------------------------------------

Write-Step 'Backend : clés de signature JWT'
# La clé privée est chiffrée avec JWT_PASSPHRASE : si .env.local vient d'être (re)créé, la phrase secrète
# est nouvelle et les anciennes clés ne s'ouvriraient plus (la connexion échouerait). On les régénère donc.
if ($envCreated) {
    Invoke-Native $Backend 'php' @('bin/console', 'lexik:jwt:generate-keypair', '--overwrite', '--no-interaction')
    Write-Ok 'clés régénérées dans backend\config\jwt\ (en accord avec le nouveau .env.local)'
} else {
    Invoke-Native $Backend 'php' @('bin/console', 'lexik:jwt:generate-keypair', '--skip-if-exists', '--no-interaction')
    Write-Ok 'clés présentes dans backend\config\jwt\'
}

# --- 5. Base de données -------------------------------------------------------------------------

Write-Step 'Base de données : création et migrations'
foreach ($environment in @('dev', 'test')) {
    Invoke-Native $Backend 'php' @('bin/console', 'doctrine:database:create', '--if-not-exists', '--no-interaction', "--env=$environment")
    Invoke-Native $Backend 'php' @('bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration', "--env=$environment")
    Write-Ok "environnement '$environment' : base prête, migrations appliquées"
}

# --- 6. Frontend --------------------------------------------------------------------------------

if ($SkipFrontend) {
    Write-Note 'Frontend ignoré (-SkipFrontend)'
} else {
    Write-Step 'Frontend : installation des dépendances (npm ci)'
    Invoke-Native $Frontend 'npm' @('ci', '--no-audit', '--no-fund')
    Write-Ok 'dépendances installées'
}

# --- 7. Tests (facultatif) ----------------------------------------------------------------------

if ($RunTests) {
    Write-Step 'Tests'
    Invoke-Native $Backend 'php' @('vendor/bin/phpunit')
    if (-not $SkipFrontend) { Invoke-Native $Frontend 'npm' @('test') }
    Write-Ok 'tous les tests passent'
}

# --- Fin ----------------------------------------------------------------------------------------

Write-Host "`nInstallation terminée." -ForegroundColor Green
Write-Host @"

Pour lancer l'application (2 terminaux) :
  1. backend  : cd backend ; php -S 127.0.0.1:8000 -t public      (ou : symfony serve --no-tls)
  2. frontend : cd frontend ; npm run dev
Puis ouvre http://localhost:5173 et crée un compte.

À planifier (supprime du disque les fichiers expirés), par exemple toutes les heures :
  cd backend ; php bin/console app:files:purge-expired
"@
