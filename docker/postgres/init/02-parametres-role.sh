#!/bin/bash
# -------------------------------------------------------------------
# Paramètres de session portés par le rôle de l'application.
#
# Laravel règle search_path et le fuseau horaire par des commandes SET
# à l'ouverture de sa connexion. Derrière PgBouncer (mode transaction),
# une connexion PHP n'est pas liée à une connexion PostgreSQL : les SET
# peuvent s'appliquer à une autre connexion que les requêtes suivantes.
# Les mêmes valeurs posées sur le rôle s'appliquent à TOUTES les
# connexions du serveur, avec ou sans PgBouncer.
#
# Base existante : exécuter les deux ALTER ROLE à la main (cf. guide
# deploy/multi-serveurs/GUIDE_BASCULE.md, étape 1).
# -------------------------------------------------------------------

set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
ALTER ROLE "$POSTGRES_USER" IN DATABASE "$POSTGRES_DB" SET search_path = fandrio_app, public;
ALTER ROLE "$POSTGRES_USER" IN DATABASE "$POSTGRES_DB" SET timezone = 'Indian/Antananarivo';
SQL

echo "=== FANDRIO: search_path et fuseau horaire posés sur le rôle $POSTGRES_USER ==="
