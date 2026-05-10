-- =============================================================================
-- RESYNC : Recalcule places_reservees depuis la source de vérité (réservations)
-- À exécuter une seule fois après le fix du double-comptage.
--
-- RÈGLE STRICTE :
--   places_reservees = SUM(nb_voyageurs) WHERE res_statut IN (1, 2)
--   (1: en attente, 2: confirmée, 3: payée — toutes occupent une place)
--   Statuts 4 (annulée) et 5 (abandonnée) ne comptent PAS.
-- =============================================================================

BEGIN;

-- Diagnostic AVANT correction (pour log)
SELECT
    v.voyage_id,
    v.places_disponibles,
    v.places_reservees AS places_reservees_avant,
    COALESCE(SUM(r.nb_voyageurs) FILTER (WHERE r.res_statut IN (1, 2)), 0) AS places_reservees_attendu,
    v.places_reservees - COALESCE(SUM(r.nb_voyageurs) FILTER (WHERE r.res_statut IN (1, 2)), 0) AS ecart
FROM fandrio_app.voyages v
LEFT JOIN fandrio_app.reservations r ON r.voyage_id = v.voyage_id
GROUP BY v.voyage_id, v.places_disponibles, v.places_reservees
HAVING v.places_reservees != COALESCE(SUM(r.nb_voyageurs) FILTER (WHERE r.res_statut IN (1, 2)), 0)
ORDER BY ecart DESC;

-- Correction : recalcul depuis la source de vérité
UPDATE fandrio_app.voyages v
SET places_reservees = COALESCE((
    SELECT SUM(r.nb_voyageurs)
    FROM fandrio_app.reservations r
    WHERE r.voyage_id = v.voyage_id
      AND r.res_statut IN (1, 2)
), 0);

-- Vérification finale : aucune ligne ne devrait apparaître
SELECT
    v.voyage_id,
    v.places_reservees,
    COALESCE(SUM(r.nb_voyageurs) FILTER (WHERE r.res_statut IN (1, 2)), 0) AS attendu
FROM fandrio_app.voyages v
LEFT JOIN fandrio_app.reservations r ON r.voyage_id = v.voyage_id
GROUP BY v.voyage_id, v.places_reservees
HAVING v.places_reservees != COALESCE(SUM(r.nb_voyageurs) FILTER (WHERE r.res_statut IN (1, 2)), 0);

COMMIT;
