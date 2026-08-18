-- =============================================================================
-- Migration : suivi de commission (gel par billet + facturation collecte + PAPI + suspension)
-- =============================================================================
-- Modèle décidé :
--   - La commission est FIGÉE par billet au moment où le voyage passe "Terminé"
--     (res_commission + res_commission_taux sur reservations).
--   - À l'échéance, le cron agrège ces montants figés dans une "collecte" et
--     rattache les réservations concernées via coll_id (composition immuable).
--   - La compagnie paie sa collecte via un lien PAPI (compte Fandrio) : on trace
--     la transaction et la date de paiement sur la collecte.
--   - Si la commission n'est pas payée 3 jours après l'échéance, la compagnie est
--     suspendue (flag comp_suspendu_commission) → voyages cachés de la recherche.
-- Script idempotent (IF NOT EXISTS). Additif : aucune perte de données.
-- =============================================================================

-- 1) RESERVATIONS : commission figée par billet + rattachement à la collecte
ALTER TABLE fandrio_app.reservations
    ADD COLUMN IF NOT EXISTS res_commission DECIMAL(10, 2) NULL,
    ADD COLUMN IF NOT EXISTS res_commission_taux DECIMAL(5, 2) NULL,
    ADD COLUMN IF NOT EXISTS coll_id INTEGER NULL;

COMMENT ON COLUMN fandrio_app.reservations.res_commission IS
    'Montant de commission FIGÉ pour ce billet, calculé au passage du voyage en "Terminé"';
COMMENT ON COLUMN fandrio_app.reservations.res_commission_taux IS
    'Taux de commission (%) figé au moment du calcul (historique juste si le taux change)';
COMMENT ON COLUMN fandrio_app.reservations.coll_id IS
    'Collecte qui a facturé cette réservation (NULL = pas encore facturée). Fige la composition.';

-- FK vers collectes : ON DELETE SET NULL pour ne pas bloquer une suppression éventuelle
ALTER TABLE fandrio_app.reservations
    DROP CONSTRAINT IF EXISTS reservations_coll_id_fkey;
ALTER TABLE fandrio_app.reservations
    ADD CONSTRAINT reservations_coll_id_fkey
    FOREIGN KEY (coll_id) REFERENCES fandrio_app.collectes(coll_id) ON DELETE SET NULL;

-- Index : "réservations d'une collecte" et "billets terminés pas encore facturés"
CREATE INDEX IF NOT EXISTS idx_reservations_coll_id ON fandrio_app.reservations(coll_id);

-- 2) COLLECTES : suivi du paiement PAPI
ALTER TABLE fandrio_app.collectes
    ADD COLUMN IF NOT EXISTS coll_papi_transaction_id VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS coll_paye_le TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS coll_mode VARCHAR(20) NOT NULL DEFAULT 'manuel';

ALTER TABLE fandrio_app.collectes
    DROP CONSTRAINT IF EXISTS collectes_coll_mode_check;
ALTER TABLE fandrio_app.collectes
    ADD CONSTRAINT collectes_coll_mode_check CHECK (coll_mode IN ('manuel', 'papi'));

COMMENT ON COLUMN fandrio_app.collectes.coll_papi_transaction_id IS
    'Identifiant de la transaction PAPI ayant réglé cette collecte';
COMMENT ON COLUMN fandrio_app.collectes.coll_paye_le IS
    'Date/heure du paiement effectif de la collecte (confirmé par webhook PAPI)';
COMMENT ON COLUMN fandrio_app.collectes.coll_mode IS
    'Mode de règlement : manuel (super-admin) ou papi (paiement en ligne)';

-- 3) COMPAGNIES : levier de suspension pour commission impayée
ALTER TABLE fandrio_app.compagnies
    ADD COLUMN IF NOT EXISTS comp_suspendu_commission BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN fandrio_app.compagnies.comp_suspendu_commission IS
    'TRUE si la compagnie est suspendue pour commission impayée (voyages cachés de la recherche client)';
