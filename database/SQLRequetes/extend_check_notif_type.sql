-- =============================================================================
-- Migration : Étendre le CHECK constraint sur notifications.notif_type
-- Le CHECK initial ne tolérait que (1,2,3,4) mais le code a évolué et utilise
-- aussi les types 8 (commission), 9 (voyage terminé), 10 (voyage annulé auto),
-- 11 (avertissement annulation J-1), 12 (paiement validé).
-- Idempotent : recrée la contrainte avec la liste à jour.
-- =============================================================================

DO $$
DECLARE
    v_constraint_name TEXT;
BEGIN
    -- Trouver le nom exact du CHECK existant sur notif_type (Postgres l'auto-nomme)
    SELECT conname INTO v_constraint_name
    FROM pg_constraint
    WHERE conrelid = 'fandrio_app.notifications'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) ILIKE '%notif_type%';

    IF v_constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE fandrio_app.notifications DROP CONSTRAINT %I', v_constraint_name);
        RAISE NOTICE 'Ancienne contrainte % supprimée.', v_constraint_name;
    END IF;

    ALTER TABLE fandrio_app.notifications
        ADD CONSTRAINT notifications_notif_type_check
        CHECK (notif_type IN (1, 2, 3, 4, 8, 9, 10, 11, 12));

    RAISE NOTICE 'CHECK étendu sur notifications.notif_type : (1,2,3,4,8,9,10,11,12).';
END $$;
