-- =============================================================================
-- Migration : ajout du suivi des remboursements aux réservations
-- =============================================================================
-- Objectif : permettre de tracker l'état du remboursement d'une réservation
-- annulée (par la compagnie ou automatiquement faute de réservations suffisantes).
--
-- Statuts remboursement :
--   0 = Non applicable (réservation active ou annulée sans paiement)
--   1 = En attente (annulée, paiement reçu, remboursement à effectuer par la compagnie)
--   2 = Traité (compagnie a effectué le remboursement, référence enregistrée)
--   3 = Refusé (cas exceptionnel : motif obligatoire)
-- =============================================================================

ALTER TABLE fandrio_app.reservations
    ADD COLUMN IF NOT EXISTS res_remb_statut INTEGER NOT NULL DEFAULT 0
        CHECK (res_remb_statut IN (0, 1, 2, 3)),
    ADD COLUMN IF NOT EXISTS res_remb_montant DECIMAL(10, 2) NULL
        CHECK (res_remb_montant IS NULL OR res_remb_montant >= 0),
    ADD COLUMN IF NOT EXISTS res_remb_date TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS res_remb_reference VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS res_remb_note TEXT NULL;

COMMENT ON COLUMN fandrio_app.reservations.res_remb_statut IS
    '0=Non applicable, 1=En attente, 2=Traité, 3=Refusé';
COMMENT ON COLUMN fandrio_app.reservations.res_remb_montant IS
    'Montant à rembourser (généralement égal à montant_avance ou montant_total payé)';
COMMENT ON COLUMN fandrio_app.reservations.res_remb_date IS
    'Date de traitement effectif du remboursement par la compagnie';
COMMENT ON COLUMN fandrio_app.reservations.res_remb_reference IS
    'Référence de la transaction de remboursement (numéro mobile money, etc.)';
COMMENT ON COLUMN fandrio_app.reservations.res_remb_note IS
    'Note libre de la compagnie (ex: motif de refus)';

-- Index pour requêtes fréquentes : "remboursements en attente d'une compagnie"
CREATE INDEX IF NOT EXISTS idx_reservations_remb_statut
    ON fandrio_app.reservations(res_remb_statut)
    WHERE res_remb_statut > 0;
