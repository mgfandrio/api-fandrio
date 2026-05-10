-- =====================================================================
-- RESET DATA — Vide les tables transactionnelles pour repartir d'une base propre
-- =====================================================================
-- Conserve les référentiels statiques :
--   • provinces            (liste des régions)
--   • types_paiement       (Mvola, Orange Money, Airtel Money, Espèces…)
--
-- Vide tout le reste, réinitialise les séquences (id repart à 1) et désactive
-- temporairement les triggers (ex. update_places_voyage) pour éviter les
-- effets de bord pendant le TRUNCATE.
--
-- Usage :
--   psql -h localhost -p 5433 -U postgres -d fandrio_app -f reset_data.sql
--
-- ⚠️  Action DESTRUCTIVE — à n'utiliser qu'en environnement de test.
-- =====================================================================

BEGIN;

SET search_path TO fandrio_app, public;

-- Désactive temporairement les triggers (replica session = ne déclenche que les triggers REPLICA)
SET session_replication_role = 'replica';

-- ---------------------------------------------------------------------
-- TRUNCATE en cascade : ordre importe peu grâce à CASCADE + RESTART IDENTITY
-- ---------------------------------------------------------------------
TRUNCATE TABLE
    fandrio_app.sieges_reserves,
    fandrio_app.plan_sieges,
    fandrio_app.audit_places,
    fandrio_app.collectes,
    fandrio_app.commissions,
    fandrio_app.notifications,
    fandrio_app.factures,
    fandrio_app.paiements,
    fandrio_app.reservation_voyageurs,
    fandrio_app.reservations,
    fandrio_app.voyages,
    fandrio_app.trajets,
    fandrio_app.compagnie_paiements,
    fandrio_app.compagnie_provinces,
    fandrio_app.voitures,
    fandrio_app.chauffeurs,
    fandrio_app.voyageurs,
    fandrio_app.utilisateurs,
    fandrio_app.compagnies
RESTART IDENTITY CASCADE;

-- Réactive les triggers
SET session_replication_role = 'origin';

COMMIT;

-- ---------------------------------------------------------------------
-- Vérification rapide : nombre de lignes restantes par table
-- ---------------------------------------------------------------------
SELECT 'compagnies'              AS table_name, COUNT(*) FROM fandrio_app.compagnies
UNION ALL SELECT 'utilisateurs',           COUNT(*) FROM fandrio_app.utilisateurs
UNION ALL SELECT 'voyageurs',              COUNT(*) FROM fandrio_app.voyageurs
UNION ALL SELECT 'chauffeurs',             COUNT(*) FROM fandrio_app.chauffeurs
UNION ALL SELECT 'voitures',               COUNT(*) FROM fandrio_app.voitures
UNION ALL SELECT 'trajets',                COUNT(*) FROM fandrio_app.trajets
UNION ALL SELECT 'voyages',                COUNT(*) FROM fandrio_app.voyages
UNION ALL SELECT 'reservations',           COUNT(*) FROM fandrio_app.reservations
UNION ALL SELECT 'reservation_voyageurs',  COUNT(*) FROM fandrio_app.reservation_voyageurs
UNION ALL SELECT 'paiements',              COUNT(*) FROM fandrio_app.paiements
UNION ALL SELECT 'factures',               COUNT(*) FROM fandrio_app.factures
UNION ALL SELECT 'notifications',          COUNT(*) FROM fandrio_app.notifications
UNION ALL SELECT 'commissions',            COUNT(*) FROM fandrio_app.commissions
UNION ALL SELECT 'collectes',              COUNT(*) FROM fandrio_app.collectes
UNION ALL SELECT 'audit_places',           COUNT(*) FROM fandrio_app.audit_places
UNION ALL SELECT 'plan_sieges',            COUNT(*) FROM fandrio_app.plan_sieges
UNION ALL SELECT 'sieges_reserves',        COUNT(*) FROM fandrio_app.sieges_reserves
UNION ALL SELECT 'compagnie_provinces',    COUNT(*) FROM fandrio_app.compagnie_provinces
UNION ALL SELECT 'compagnie_paiements',    COUNT(*) FROM fandrio_app.compagnie_paiements
-- Référentiels CONSERVÉS (à titre informatif)
UNION ALL SELECT 'provinces (gardé)',      COUNT(*) FROM fandrio_app.provinces
UNION ALL SELECT 'types_paiement (gardé)', COUNT(*) FROM fandrio_app.types_paiement
ORDER BY table_name;

-- =====================================================================
-- 🟥 OPTION : RESET TOTAL (si tu veux aussi vider provinces & types_paiement)
-- =====================================================================
-- Décommente le bloc ci-dessous pour tout effacer (référentiels inclus) :
--
-- BEGIN;
-- SET session_replication_role = 'replica';
-- TRUNCATE TABLE
--     fandrio_app.types_paiement,
--     fandrio_app.provinces
-- RESTART IDENTITY CASCADE;
-- SET session_replication_role = 'origin';
-- COMMIT;
