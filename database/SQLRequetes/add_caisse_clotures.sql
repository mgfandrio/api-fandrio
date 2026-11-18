-- =============================================================================
-- Migration : caisse du guichet (clôture du soir)
--
-- Une ligne par vendeur et par jour : espèces attendues (ventes au guichet du
-- vendeur ce jour-là), espèces comptées, écart (compté − attendu), détail du
-- comptage billet par billet, remarque (obligatoire si écart), auteur.
-- Une vente faite après la clôture rend la caisse « à reclôturer » : la
-- nouvelle clôture remplace la précédente (même vendeur, même jour).
-- Idempotent.
-- =============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS fandrio_app.caisse_clotures (
    clot_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    vendeur_id INTEGER NOT NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE CASCADE,
    clot_date DATE NOT NULL,
    clot_attendu DECIMAL(12,2) NOT NULL CHECK (clot_attendu >= 0),
    clot_compte DECIMAL(12,2) NOT NULL CHECK (clot_compte >= 0),
    clot_ecart DECIMAL(12,2) NOT NULL,
    clot_nb_ventes INTEGER NOT NULL DEFAULT 0,
    clot_nb_billets INTEGER NOT NULL DEFAULT 0,
    clot_billetage JSONB NULL,
    clot_note TEXT NULL,
    clot_par INTEGER NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT caisse_clotures_unique UNIQUE (comp_id, vendeur_id, clot_date)
);
CREATE INDEX IF NOT EXISTS idx_caisse_clotures_comp_date ON fandrio_app.caisse_clotures (comp_id, clot_date);

-- Ventes au guichet d'un vendeur sur une journée
CREATE INDEX IF NOT EXISTS idx_reservations_guichet_vendeur
    ON fandrio_app.reservations (util_id, created_at) WHERE res_canal = 2;

DROP TRIGGER IF EXISTS update_caisse_clotures_updated_at ON fandrio_app.caisse_clotures;
CREATE TRIGGER update_caisse_clotures_updated_at BEFORE UPDATE ON fandrio_app.caisse_clotures
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();

COMMENT ON TABLE fandrio_app.caisse_clotures IS
    'Clôtures de caisse du guichet : une par vendeur et par jour (attendu, compté, écart)';
COMMENT ON COLUMN fandrio_app.caisse_clotures.clot_ecart IS 'Compté − attendu : négatif = il manque de l''argent';
COMMENT ON COLUMN fandrio_app.caisse_clotures.clot_billetage IS 'Comptage facultatif : {"20000": 8, "10000": 6, ...}';

COMMIT;
