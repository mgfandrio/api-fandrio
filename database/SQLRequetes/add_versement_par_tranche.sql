-- =============================================================================
-- COMMISSION FANDRIO VERSÉE PAR TRANCHE DE VOYAGES (remplace hebdo/mensuel)
--
-- La commission reste comptée sur chaque billet vendu en ligne. Dès que la
-- compagnie atteint N voyages terminés avec ventes en ligne (5 par défaut,
-- réglable par l'admin système), la commission cumulée devient « à verser ».
-- Sans atteindre N voyages, elle est due 30 jours après le 1er voyage de la tranche.
--
-- Rappel chaque jour tant que non versé, avertissement à 7 jours, suspension à 14 jours.
-- Idempotent : peut être rejoué sans risque.
-- =============================================================================

-- 1. Paramètres par compagnie
ALTER TABLE fandrio_app.compagnies
    ADD COLUMN IF NOT EXISTS comm_mode_versement VARCHAR(12) NOT NULL DEFAULT 'manuel';
ALTER TABLE fandrio_app.compagnies
    ADD COLUMN IF NOT EXISTS comm_seuil_voyages INTEGER NOT NULL DEFAULT 5;

ALTER TABLE fandrio_app.compagnies DROP CONSTRAINT IF EXISTS chk_mode_versement;
ALTER TABLE fandrio_app.compagnies
    ADD CONSTRAINT chk_mode_versement CHECK (comm_mode_versement IN ('manuel', 'automatique'));
ALTER TABLE fandrio_app.compagnies DROP CONSTRAINT IF EXISTS chk_seuil_voyages;
ALTER TABLE fandrio_app.compagnies
    ADD CONSTRAINT chk_seuil_voyages CHECK (comm_seuil_voyages BETWEEN 1 AND 100);

COMMENT ON COLUMN fandrio_app.compagnies.comm_mode_versement IS 'manuel : la compagnie clique « Verser la commission » ; automatique : le lien de paiement est envoyé dès que la tranche est atteinte';
COMMENT ON COLUMN fandrio_app.compagnies.comm_seuil_voyages IS 'Nombre de voyages terminés (avec ventes en ligne) par tranche de commission';

-- Anciennes colonnes de fréquence calendaire : retirées
ALTER TABLE fandrio_app.compagnies DROP CONSTRAINT IF EXISTS chk_frequence_collecte;
ALTER TABLE fandrio_app.compagnies DROP COLUMN IF EXISTS comm_frequence_collecte;
ALTER TABLE fandrio_app.compagnies DROP COLUMN IF EXISTS comm_jour_collecte;

-- 2. Collectes = tranches
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_nb_voyages INTEGER NOT NULL DEFAULT 0;
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_motif VARCHAR(10) NOT NULL DEFAULT 'seuil';
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_derniere_relance DATE NULL;
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_avertie_le TIMESTAMP NULL;
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_papi_url TEXT NULL;
ALTER TABLE fandrio_app.collectes ADD COLUMN IF NOT EXISTS coll_initie_par INTEGER NULL REFERENCES fandrio_app.utilisateurs(util_id);

ALTER TABLE fandrio_app.collectes DROP CONSTRAINT IF EXISTS chk_coll_motif;
ALTER TABLE fandrio_app.collectes
    ADD CONSTRAINT chk_coll_motif CHECK (coll_motif IN ('seuil', 'delai'));

-- Plusieurs tranches peuvent couvrir les mêmes dates (grosse compagnie) : plus d'unicité par période
ALTER TABLE fandrio_app.collectes DROP CONSTRAINT IF EXISTS collectes_comp_id_coll_periode_debut_coll_periode_fin_key;

COMMENT ON COLUMN fandrio_app.collectes.coll_nb_voyages IS 'Nombre de voyages compris dans la tranche';
COMMENT ON COLUMN fandrio_app.collectes.coll_motif IS 'seuil : tranche de N voyages atteinte ; delai : 30 jours écoulés avant le seuil';
COMMENT ON COLUMN fandrio_app.collectes.coll_initie_par IS 'Admin compagnie qui a lancé le versement';

-- 3. Voyage → tranche qui l'a compté
ALTER TABLE fandrio_app.voyages ADD COLUMN IF NOT EXISTS coll_id INTEGER NULL;
ALTER TABLE fandrio_app.voyages DROP CONSTRAINT IF EXISTS voyages_coll_id_fkey;
ALTER TABLE fandrio_app.voyages
    ADD CONSTRAINT voyages_coll_id_fkey FOREIGN KEY (coll_id) REFERENCES fandrio_app.collectes(coll_id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_voyages_coll_id ON fandrio_app.voyages(coll_id);
COMMENT ON COLUMN fandrio_app.voyages.coll_id IS 'Tranche de commission qui a compté ce voyage (NULL = pas encore compté)';

-- 4. Journal des actions (qui a fait quoi, quand)
CREATE TABLE IF NOT EXISTS fandrio_app.collecte_journal (
    cj_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    coll_id INTEGER NULL REFERENCES fandrio_app.collectes(coll_id) ON DELETE SET NULL,
    util_id INTEGER NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE SET NULL,
    cj_acteur VARCHAR(20) NOT NULL DEFAULT 'systeme' CHECK (cj_acteur IN ('systeme', 'admin_compagnie', 'admin_systeme', 'paiement')),
    cj_action VARCHAR(40) NOT NULL,
    cj_message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_collecte_journal_comp ON fandrio_app.collecte_journal(comp_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_collecte_journal_coll ON fandrio_app.collecte_journal(coll_id);
COMMENT ON TABLE fandrio_app.collecte_journal IS 'Historique des actions sur les commissions FANDRIO (tranches, rappels, versements, paramètres)';
