-- =============================================================================
-- Migration : canal de vente d'une réservation (res_canal)
--
--   1 = en ligne (application FANDRIO, paiement mobile money / carte)
--   2 = guichet  (vendue sur place par la compagnie, payée en espèces)
--
-- Une vente au guichet ne passe pas par FANDRIO : AUCUNE commission n'est due.
-- Elle est créée directement confirmée (res_statut 2), avec res_commission = 0,
-- et exclue de tous les calculs de commission / collecte / statistiques FANDRIO.
-- Elle occupe ses sièges comme une réservation en ligne (trigger inchangé).
-- util_id = l'admin compagnie qui a fait la vente (colonne NOT NULL).
-- Idempotent.
-- =============================================================================

BEGIN;

ALTER TABLE fandrio_app.reservations
    ADD COLUMN IF NOT EXISTS res_canal SMALLINT NOT NULL DEFAULT 1;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'fandrio_app.reservations'::regclass
          AND conname = 'reservations_res_canal_check'
    ) THEN
        ALTER TABLE fandrio_app.reservations
            ADD CONSTRAINT reservations_res_canal_check CHECK (res_canal IN (1, 2));
    END IF;
END $$;

COMMENT ON COLUMN fandrio_app.reservations.res_canal IS
    '1: en ligne (application), 2: guichet (vente sur place en espèces, sans commission FANDRIO)';

COMMIT;
