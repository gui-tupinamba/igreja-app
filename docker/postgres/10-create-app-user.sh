#!/bin/sh
(
set -eu

# Runs only when the data volume is empty. Values are quoted by psql, not SQL interpolation.
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set ON_ERROR_STOP=1 \
    --set app_user="$APP_DATABASE_USER" \
    --set app_password="$APP_DATABASE_PASSWORD" \
    --set app_db="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user" WITH LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
REVOKE ALL ON DATABASE :"app_db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"app_db" TO :"app_user";
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO :"app_user";
SQL
)
