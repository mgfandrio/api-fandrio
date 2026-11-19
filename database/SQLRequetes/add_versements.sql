-- =============================================================================
-- Migration : versements aux chauffeurs (voitures de particuliers)
--
-- 1. Voitures : voit_proprietaire 1 = compagnie, 2 = particulier ;
--    voit_taux_compagnie = pourcentage pris par la compagnie sur chaque siège
--    vendu (après la commission FANDRIO des ventes en ligne), obligatoire pour
--    une voiture de particulier. Les voitures existantes : à la compagnie.
-- 2. versements : un par voyage d'une voiture de particulier. Les montants sont
--    recalculés tant que la voiture n'est pas partie, puis FIGÉS au départ
--    (vers_fige_le) avec la photo de l'instant : matricule, chauffeur (id et
--    nom), trajet, date, pourcentage. Le règlement garde qui et quand.
-- 3. Notification 18 : rappel de versement à régler.
-- Idempotent.
-- =============================================================================

BEGIN;

ALTER TABLE fandrio_app.voitures
    ADD COLUMN IF NOT EXISTS voit_proprietaire SMALLINT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS voit_taux_compagnie DECIMAL(5,2) NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'voitures_proprietaire_check') THEN
        ALTER TABLE fandrio_app.voitures ADD CONSTRAINT voitures_proprietaire_check CHECK (voit_proprietaire IN (1, 2));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'voitures_taux_compagnie_check') THEN
        ALTER TABLE fandrio_app.voitures ADD CONSTRAINT voitures_taux_compagnie_check
            CHECK (voit_taux_compagnie IS NULL OR (voit_taux_compagnie >= 0 AND voit_taux_compagnie <= 100));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'voitures_particulier_taux_check') THEN
        ALTER TABLE fandrio_app.voitures ADD CONSTRAINT voitures_particulier_taux_check
            CHECK (voit_proprietaire = 1 OR voit_taux_compagnie IS NOT NULL);
    END IF;
END $$;

COMMENT ON COLUMN fandrio_app.voitures.voit_proprietaire IS '1 = voiture de la compagnie, 2 = voiture d''un particulier (versement au chauffeur)';
COMMENT ON COLUMN fandrio_app.voitures.voit_taux_compagnie IS 'Pourcentage pris par la compagnie sur chaque siège vendu (voiture de particulier)';

CREATE TABLE IF NOT EXISTS fandrio_app.versements (
    vers_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    voyage_id INTEGER NOT NULL UNIQUE REFERENCES fandrio_app.voyages(voyage_id) ON DELETE CASCADE,
    -- Photo de l'instant (figée au départ) : la voiture et le chauffeur peuvent changer ensuite
    voit_id INTEGER NULL REFERENCES fandrio_app.voitures(voit_id) ON DELETE SET NULL,
    voit_matricule VARCHAR(20) NOT NULL,
    chauff_id INTEGER NULL REFERENCES fandrio_app.chauffeurs(chauff_id) ON DELETE SET NULL,
    chauffeur_nom VARCHAR(200) NULL,
    chauffeur_phone VARCHAR(20) NULL,
    traj_id INTEGER NULL REFERENCES fandrio_app.trajets(traj_id) ON DELETE SET NULL,
    trajet_libelle VARCHAR(200) NOT NULL,
    voyage_date DATE NOT NULL,
    voyage_heure TIME NOT NULL,
    taux_compagnie DECIMAL(5,2) NOT NULL,
    nb_places INTEGER NOT NULL DEFAULT 0,
    nb_sieges INTEGER NOT NULL DEFAULT 0,
    montant_ventes DECIMAL(12,2) NOT NULL DEFAULT 0,
    commission_fandrio DECIMAL(12,2) NOT NULL DEFAULT 0,
    part_compagnie DECIMAL(12,2) NOT NULL DEFAULT 0,
    part_chauffeur DECIMAL(12,2) NOT NULL DEFAULT 0,
    vers_statut SMALLINT NOT NULL DEFAULT 1 CHECK (vers_statut IN (1, 2)), -- 1 à régler, 2 réglé
    vers_fige_le TIMESTAMP NULL,       -- départ de la voiture (ou voyage passé) : montants définitifs
    vers_regle_le TIMESTAMP NULL,
    vers_regle_par INTEGER NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE SET NULL,
    vers_regle_par_nom VARCHAR(200) NULL,
    vers_rappel_le TIMESTAMP NULL,     -- dernier rappel envoyé
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_versements_comp_statut ON fandrio_app.versements (comp_id, vers_statut);
CREATE INDEX IF NOT EXISTS idx_versements_comp_date ON fandrio_app.versements (comp_id, voyage_date);
CREATE INDEX IF NOT EXISTS idx_versements_voiture ON fandrio_app.versements (voit_id);
CREATE INDEX IF NOT EXISTS idx_versements_chauffeur ON fandrio_app.versements (chauff_id);

DROP TRIGGER IF EXISTS update_versements_updated_at ON fandrio_app.versements;
CREATE TRIGGER update_versements_updated_at BEFORE UPDATE ON fandrio_app.versements
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();

COMMENT ON TABLE fandrio_app.versements IS
    'Versements aux chauffeurs des voitures de particuliers : un par voyage, figé au départ, réglé par la compagnie';

-- Notification 18 : rappel de versement à régler
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
        CHECK (notif_type IN (1, 2, 3, 4, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18));
END $$;

COMMIT;
