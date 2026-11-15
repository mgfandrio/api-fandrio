-- =============================================================================
-- Migration : compagnies partenaires et suivi des voitures en route
--
-- 1. compagnie_partenaires : lien réciproque entre deux compagnies (une ligne par
--    paire, comp_a < comp_b). Actif dès l'ajout, retirable par l'une ou l'autre.
--
-- 2. Voyages :
--    comp_reception        compagnie partenaire qui accueille la voiture à destination
--                          (située dans la province d'arrivée du trajet) ; NULL = suivi
--                          interne par la compagnie de départ
--    voyage_suivi          NULL = pas encore parti, 1 = en route, 2 = arrivé
--    voyage_depart_le      instant du départ (heure de Madagascar)
--    voyage_depart_auto    TRUE si l'embarquement n'a pas été clôturé : passage
--                          automatique « en route » 1 h après l'heure de départ
--    voyage_arrivee_le     instant où l'arrivée a été confirmée
--    voyage_arrivee_par    compagnie qui a confirmé l'arrivée
--    voyage_retard_notifie alerte de retard déjà envoyée
--
-- 3. Notifications : 13 voyage en route (réception), 14 voyage arrivé (départ),
--    15 retard (les deux), 16 départ automatique (départ), 17 partenariat.
-- Idempotent.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS fandrio_app.compagnie_partenaires (
    cp_id SERIAL PRIMARY KEY,
    comp_a INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    comp_b INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    cp_cree_par INTEGER NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT compagnie_partenaires_ordre CHECK (comp_a < comp_b),
    CONSTRAINT compagnie_partenaires_unique UNIQUE (comp_a, comp_b)
);
CREATE INDEX IF NOT EXISTS idx_compagnie_partenaires_b ON fandrio_app.compagnie_partenaires (comp_b);

COMMENT ON TABLE fandrio_app.compagnie_partenaires IS
    'Partenariats réciproques entre compagnies (comp_a < comp_b) : la compagnie de destination reçoit le suivi des voitures en route';
COMMENT ON COLUMN fandrio_app.compagnie_partenaires.cp_cree_par IS
    'Compagnie qui a créé le lien ; NULL = créé par l''admin système';

ALTER TABLE fandrio_app.voyages
    ADD COLUMN IF NOT EXISTS comp_reception INTEGER NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS voyage_suivi SMALLINT NULL,
    ADD COLUMN IF NOT EXISTS voyage_depart_le TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS voyage_depart_auto BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS voyage_arrivee_le TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS voyage_arrivee_par INTEGER NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS voyage_retard_notifie BOOLEAN NOT NULL DEFAULT FALSE;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'fandrio_app.voyages'::regclass AND conname = 'voyages_suivi_check'
    ) THEN
        ALTER TABLE fandrio_app.voyages
            ADD CONSTRAINT voyages_suivi_check CHECK (voyage_suivi IS NULL OR voyage_suivi IN (1, 2));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_voyages_suivi ON fandrio_app.voyages (voyage_suivi) WHERE voyage_suivi IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_voyages_comp_reception ON fandrio_app.voyages (comp_reception) WHERE comp_reception IS NOT NULL;

COMMENT ON COLUMN fandrio_app.voyages.comp_reception IS
    'Compagnie partenaire qui accueille la voiture à destination ; NULL = suivi par la compagnie de départ seule';
COMMENT ON COLUMN fandrio_app.voyages.voyage_suivi IS 'Suivi de la voiture : NULL pas parti, 1 en route, 2 arrivé';
COMMENT ON COLUMN fandrio_app.voyages.voyage_depart_auto IS
    'TRUE si l''embarquement n''a pas été clôturé : passé en route automatiquement 1 h après l''heure de départ';

-- Types de notification
DO $$
DECLARE
    v_constraint_name TEXT;
BEGIN
    SELECT conname INTO v_constraint_name
    FROM pg_constraint
    WHERE conrelid = 'fandrio_app.notifications'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) ILIKE '%notif_type%';

    IF v_constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE fandrio_app.notifications DROP CONSTRAINT %I', v_constraint_name);
    END IF;

    ALTER TABLE fandrio_app.notifications
        ADD CONSTRAINT notifications_notif_type_check
        CHECK (notif_type IN (1, 2, 3, 4, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17));
END $$;

COMMIT;
