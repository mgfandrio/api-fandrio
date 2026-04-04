#!/bin/bash
# -------------------------------------------------------------------
# Initialisation de la base de données FANDRIO
# Ce script est exécuté automatiquement au premier démarrage du
# conteneur PostgreSQL via /docker-entrypoint-initdb.d/
# -------------------------------------------------------------------

set -e

echo "=== FANDRIO: Initialisation de la base de données ==="

# Le fichier principal crée le schéma fandrio_app et toutes les tables
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -f /docker-entrypoint-initdb.d/sql/fandrioSQL_v1.0.sql

echo "=== FANDRIO: Tables principales créées ==="

# Scripts de migration incrémentaux
for f in \
    add_geolocation_to_provinces.sql \
    add_comp_localisation_to_compagnies.sql \
    add_details_to_compagnie_paiements.sql \
    add_voyage_is_active_column.sql \
    securisation_numero_paiement.sql \
    add_systeme_collecte_commissions.sql \
    add_frequence_collecte_commission.sql
do
    if [ -f "/docker-entrypoint-initdb.d/sql/$f" ]; then
        echo "  -> Exécution: $f"
        psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
            -f "/docker-entrypoint-initdb.d/sql/$f"
    else
        echo "  -> Ignoré (fichier absent): $f"
    fi
done

echo "=== FANDRIO: Base de données initialisée avec succès ==="
