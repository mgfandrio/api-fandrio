#!/bin/bash
# Génère la configuration de PgBouncer à partir des variables d'environnement,
# puis le démarre. Les identifiants sont ceux de l'application (DB_USERNAME /
# DB_PASSWORD) : PgBouncer les vérifie (SCRAM) puis se connecte à PostgreSQL.
set -euo pipefail

: "${DB_USERNAME:?DB_USERNAME manquant}"
: "${DB_PASSWORD:?DB_PASSWORD manquant}"
: "${DB_DATABASE:?DB_DATABASE manquant}"
PG_HOST="${PG_HOST:-postgres}"
PG_PORT="${PG_PORT:-5432}"

# Fichier d'utilisateurs : mot de passe en clair, lisible par PgBouncer seul
umask 077
printf '"%s" "%s"\n' "$DB_USERNAME" "${DB_PASSWORD//\"/\"\"}" > /etc/pgbouncer/userlist.txt

cat > /etc/pgbouncer/pgbouncer.ini <<INI
[databases]
${DB_DATABASE} = host=${PG_HOST} port=${PG_PORT} dbname=${DB_DATABASE}

[pgbouncer]
listen_addr = 0.0.0.0
listen_port = 6432
unix_socket_dir =
auth_type = scram-sha-256
auth_file = /etc/pgbouncer/userlist.txt

; Une connexion PostgreSQL par transaction (et non par client)
pool_mode = transaction
; Connexions clientes acceptées (tous les processus PHP de tous les serveurs)
max_client_conn = ${PGBOUNCER_MAX_CLIENT_CONN:-2000}
; Connexions réellement ouvertes vers PostgreSQL (rester sous max_connections = 100)
default_pool_size = ${PGBOUNCER_POOL_SIZE:-40}
reserve_pool_size = ${PGBOUNCER_RESERVE_POOL:-10}
reserve_pool_timeout = 3
; Requêtes préparées de PDO en mode transaction (PgBouncer >= 1.21)
max_prepared_statements = ${PGBOUNCER_MAX_PREPARED:-200}
; Paramètres envoyés par certains clients au démarrage, sans effet ici
ignore_startup_parameters = extra_float_digits,options

server_idle_timeout = 300
query_wait_timeout = 30
log_connections = 0
log_disconnections = 0
admin_users = ${DB_USERNAME}
stats_users = ${DB_USERNAME}
INI

exec pgbouncer /etc/pgbouncer/pgbouncer.ini
