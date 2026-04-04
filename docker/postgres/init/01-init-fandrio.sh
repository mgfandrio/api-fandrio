#!/bin/bash
# -------------------------------------------------------------------
# Initialisation de la base de données FANDRIO
# Ce script est exécuté automatiquement au premier démarrage du
# conteneur PostgreSQL via /docker-entrypoint-initdb.d/
# -------------------------------------------------------------------

set -e

echo "=== FANDRIO: Initialisation de la base de données ==="

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -f /docker-entrypoint-initdb.d/sql/fandrioSQL_v1.0.sql

echo "=== FANDRIO: Base de données initialisée avec succès ==="
