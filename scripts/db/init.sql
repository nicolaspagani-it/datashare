-- ============================================================================
-- Initialisation de PostgreSQL pour DataShare : crée l'utilisateur et la base.
--
-- À exécuter UNE FOIS par un administrateur PostgreSQL (le super-utilisateur "postgres") :
--
--   psql -U postgres -v db_user=datashare -v db_password='un-mot-de-passe' -v db_name=datashare -f scripts/db/init.sql
--
-- Le script est rejouable : il ne recrée pas ce qui existe déjà.
-- Les TABLES, elles, sont créées par les migrations Doctrine (scripts/install.ps1 ou install.sh,
-- ou : cd backend && php bin/console doctrine:migrations:migrate).
-- ============================================================================

\set ON_ERROR_STOP on

-- L'utilisateur de l'application. CREATEDB est nécessaire : les tests créent la base "<nom>_test".
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L CREATEDB', :'db_user', :'db_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'db_user')
\gexec

-- La base de l'application, en UTF-8, dont l'utilisateur ci-dessus est propriétaire.
SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', :'db_name', :'db_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'db_name')
\gexec
