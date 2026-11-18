-- =============================================================================
-- Migration : motif du remboursement enregistré à sa création
--
-- res_remb_motif : 1 = voyage annulé (par la compagnie ou automatiquement)
--                  2 = paiement arrivé après le délai (sièges déjà libérés)
--
-- Avant, le motif était déduit du statut ACTUEL du voyage : un voyage annulé
-- puis réactivé ou reprogrammé faisait passer ses remboursements pour des
-- « paiements arrivés trop tard ». Les lignes existantes sont complétées au
-- mieux (voyage annulé → 1, sinon → 2).
-- Idempotent.
-- =============================================================================

BEGIN;

ALTER TABLE fandrio_app.reservations
    ADD COLUMN IF NOT EXISTS res_remb_motif SMALLINT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'reservations_res_remb_motif_check') THEN
        ALTER TABLE fandrio_app.reservations
            ADD CONSTRAINT reservations_res_remb_motif_check CHECK (res_remb_motif IS NULL OR res_remb_motif IN (1, 2));
    END IF;
END $$;

COMMENT ON COLUMN fandrio_app.reservations.res_remb_motif IS
    'Motif du remboursement : 1=Voyage annulé, 2=Paiement arrivé trop tard (NULL si aucun remboursement)';

UPDATE fandrio_app.reservations r
SET res_remb_motif = CASE WHEN v.voyage_statut = 4 THEN 1 ELSE 2 END
FROM fandrio_app.voyages v
WHERE v.voyage_id = r.voyage_id
  AND r.res_remb_statut > 0
  AND r.res_remb_motif IS NULL;

COMMIT;
