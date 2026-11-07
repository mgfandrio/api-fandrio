-- =============================================================================
-- Index de performance (audit de charge)
-- =============================================================================
-- PostgreSQL n'indexe pas automatiquement les clés étrangères : ces index
-- couvrent les colonnes filtrées par les requêtes fréquentes de l'API.
--
-- CONCURRENTLY : l'index est construit SANS bloquer les écritures sur la table
-- (utilisable sur une base en production). Contraintes :
--   - ne pas exécuter dans une transaction (pas de BEGIN, pas de `psql -1`) ;
--   - si une création échoue, l'index reste "INVALID" : le supprimer
--     (DROP INDEX CONCURRENTLY nom_index;) puis relancer ce script.
-- IF NOT EXISTS : le script peut être relancé sans risque.
--
-- Exécution : psql -U <user> -d <base> -f add_index_performance.sql
-- =============================================================================

-- Réservations d'un client (dashboard, historique, contrôles d'accès)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reservations_utilisateur
    ON fandrio_app.reservations(util_id);

-- Réservations d'un voyage par statut (réservation, libération, stats, gestion statuts)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reservations_voyage_statut
    ON fandrio_app.reservations(voyage_id, res_statut);

-- Réservations en attente à libérer (tâche planifiée chaque minute)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reservations_expiration
    ON fandrio_app.reservations(date_limite_paiement) WHERE res_statut = 1;

-- Recherche / accueil / tâche de gestion des statuts (statut + date)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_voyages_statut_date
    ON fandrio_app.voyages(voyage_statut, voyage_date);

-- Jointure voyages ↔ trajets (recherche, accueil, voyages d'une compagnie)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_voyages_trajet
    ON fandrio_app.voyages(traj_id);

-- Disponibilité d'un véhicule à une date
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_voyages_voiture
    ON fandrio_app.voyages(voit_id);

-- Liste / compteur des notifications d'un utilisateur
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notifications_destinataire_date
    ON fandrio_app.notifications(notif_destinataire_id, created_at DESC);

-- Admins d'une compagnie (notifications à chaque réservation confirmée)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_utilisateurs_compagnie
    ON fandrio_app.utilisateurs(comp_id) WHERE comp_id IS NOT NULL;

-- Listes admin compagnie
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_voitures_compagnie
    ON fandrio_app.voitures(comp_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_chauffeurs_compagnie
    ON fandrio_app.chauffeurs(comp_id);

-- Voyageurs ("mes voyageurs" côté client, liaison avec les réservations)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_voyageurs_utilisateur
    ON fandrio_app.voyageurs(util_id);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reservation_voyageurs_voyageur
    ON fandrio_app.reservation_voyageurs(voya_id);

-- Compagnie ↔ provinces (classement des compagnies par province sur l'accueil)
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_compagnie_provinces_province
    ON fandrio_app.compagnie_provinces(pro_id);

-- Mettre à jour les statistiques du planificateur après création des index
ANALYZE fandrio_app.reservations;
ANALYZE fandrio_app.voyages;
ANALYZE fandrio_app.notifications;
