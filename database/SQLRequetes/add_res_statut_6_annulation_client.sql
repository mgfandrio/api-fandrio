-- =============================================================================
-- Migration : res_statut = 6 « annulée par le client, non remboursée »
--
-- Le client peut annuler lui-même un billet PAYÉ jusqu'à 24 h avant le départ.
-- Pas de remboursement : la compagnie garde le paiement, et la commission
-- FANDRIO reste due (le statut 6 est compté comme encaissé dans les calculs
-- de commission, de collecte et de portefeuille).
--
-- Statuts après migration :
--   1 = en attente de paiement   2 = confirmée (payée)
--   4 = annulée (compagnie / expirée, remboursement possible)
--   5 = abandonnée (paiement non fait)
--   6 = annulée par le client, non remboursée
--
-- Le trigger update_places_voyage ne compte que les statuts 1 et 2 : passer
-- un billet en 6 libère donc automatiquement places_reservees.
-- Idempotent.
-- =============================================================================

BEGIN;

DO $$
DECLARE
    v_constraint_name TEXT;
BEGIN
    SELECT conname INTO v_constraint_name
    FROM pg_constraint
    WHERE conrelid = 'fandrio_app.reservations'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) ILIKE '%(res_statut%';

    IF v_constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE fandrio_app.reservations DROP CONSTRAINT %I', v_constraint_name);
    END IF;

    ALTER TABLE fandrio_app.reservations
        ADD CONSTRAINT reservations_res_statut_check
        CHECK (res_statut IN (1, 2, 4, 5, 6));
END $$;

COMMENT ON COLUMN fandrio_app.reservations.res_statut IS
    '1: en attente, 2: confirmée (payée), 4: annulée (remboursable), 5: abandonnée, 6: annulée par le client (non remboursée, commission due)';

COMMIT;
