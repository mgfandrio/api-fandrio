-- =============================================================================
-- FANDRIO — Données du test de charge
-- =============================================================================
-- ⚠️ À exécuter UNIQUEMENT sur une base de TEST (jamais en production) :
--   docker exec -i fandrio_postgres psql -U <user> -d <base> \
--       -v confirmer_base_de_test=1 < loadtest/seed.sql
--
-- Crée : 20 compagnies, 2 000 clients, 200 véhicules (plan 18 places),
--        200 trajets, 3 000 voyages programmés sur les 30 prochains jours.
-- Comptes clients : charge1@test.fandrio … charge2000@test.fandrio
-- Mot de passe commun : Charge2026!
-- =============================================================================

\if :{?confirmer_base_de_test}
\else
    \echo 'ARRÊT : ajoutez -v confirmer_base_de_test=1 pour confirmer que cette base est une base de TEST.'
    \quit
\endif

\set ON_ERROR_STOP on
SET search_path TO fandrio_app, public;

BEGIN;

-- Compagnies actives
INSERT INTO compagnies (comp_nom, comp_statut, comp_suspendu_commission)
SELECT 'Charge Transport ' || i, 1, false
FROM generate_series(1, 20) i;

-- Clients (rôle 1), mot de passe : Charge2026!
INSERT INTO utilisateurs (util_nom, util_prenom, util_email, util_phone, util_password, util_role, util_statut)
SELECT 'Charge', 'Client ' || i, 'charge' || i || '@test.fandrio', '0399' || lpad(i::text, 6, '0'),
       '$2y$10$avexwBGq5OhZq5v3DyNb4eKYQ/lpY9SslpZTVpc/YPgypO38OR1eS', 1, 1
FROM generate_series(1, 2000) i;

-- Véhicules de 18 places, répartis entre les compagnies de test
INSERT INTO voitures (voit_matricule, voit_marque, voit_modele, voit_places, comp_id)
SELECT 'CHG' || lpad(i::text, 4, '0'), 'Mercedes', 'Sprinter', 18, c.comp_id
FROM generate_series(1, 200) i
JOIN LATERAL (
    SELECT comp_id FROM compagnies WHERE comp_nom LIKE 'Charge Transport %'
    ORDER BY comp_id OFFSET (i % 20) LIMIT 1
) c ON true;

-- Plan de sièges : 18 sièges numérotés 1 à 18
INSERT INTO plan_sieges (voit_id, config_sieges)
SELECT voit_id, '{"sieges":[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18]}'::jsonb
FROM voitures WHERE voit_matricule LIKE 'CHG%';

-- Trajets entre provinces existantes (départ ≠ arrivée), tarifs variés
INSERT INTO trajets (traj_nom, pro_depart, pro_arrivee, traj_tarif, comp_id)
SELECT 'Charge ' || d.pro_nom || ' - ' || a.pro_nom, d.pro_id, a.pro_id,
       15000 + (i % 8) * 5000, c.comp_id
FROM generate_series(1, 200) i
JOIN LATERAL (SELECT pro_id, pro_nom FROM provinces ORDER BY pro_id OFFSET (i % (SELECT count(*) FROM provinces)) LIMIT 1) d ON true
JOIN LATERAL (SELECT pro_id, pro_nom FROM provinces ORDER BY pro_id OFFSET ((i / 7 + 1 + i) % (SELECT count(*) FROM provinces)) LIMIT 1) a ON true
JOIN LATERAL (
    SELECT comp_id FROM compagnies WHERE comp_nom LIKE 'Charge Transport %'
    ORDER BY comp_id OFFSET (i % 20) LIMIT 1
) c ON true
WHERE d.pro_id <> a.pro_id;

-- Voyages programmés : 3 000 sur les 30 prochains jours, véhicule de la même compagnie
INSERT INTO voyages (voyage_date, voyage_heure_depart, traj_id, voit_id, places_disponibles, voyage_statut, voyage_is_active)
SELECT current_date + 1 + (i % 30),
       (time '05:00' + ((i % 12) * interval '1 hour')),
       t.traj_id, v.voit_id, 18, 1, true
FROM generate_series(1, 3000) i
JOIN LATERAL (SELECT traj_id, comp_id FROM trajets WHERE traj_nom LIKE 'Charge %' ORDER BY traj_id OFFSET (i % (SELECT count(*) FROM trajets WHERE traj_nom LIKE 'Charge %')) LIMIT 1) t ON true
JOIN LATERAL (SELECT voit_id FROM voitures WHERE comp_id = t.comp_id AND voit_matricule LIKE 'CHG%' ORDER BY voit_id OFFSET (i % 10) LIMIT 1) v ON true;

COMMIT;

ANALYZE;

SELECT 'clients' AS donnees, count(*) FROM utilisateurs WHERE util_email LIKE 'charge%@test.fandrio'
UNION ALL SELECT 'trajets', count(*) FROM trajets WHERE traj_nom LIKE 'Charge %'
UNION ALL SELECT 'voyages programmés', count(*) FROM voyages v JOIN trajets t USING (traj_id) WHERE t.traj_nom LIKE 'Charge %'
UNION ALL SELECT 'provinces desservies (départ)', count(DISTINCT pro_depart) FROM trajets WHERE traj_nom LIKE 'Charge %';
