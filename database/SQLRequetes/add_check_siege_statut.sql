-- =============================================================================
-- Migration : Ajout du CHECK constraint sur siege_statut
-- Aligne sieges_reserves avec les autres tables (CHECK sur tous les *_statut).
-- Idempotent : ne fait rien si la contrainte existe déjà.
-- =============================================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'sieges_reserves_siege_statut_check'
          AND conrelid = 'fandrio_app.sieges_reserves'::regclass
    ) THEN
        -- Nettoyer d'éventuelles valeurs invalides avant d'ajouter le CHECK
        UPDATE fandrio_app.sieges_reserves
           SET siege_statut = 2
         WHERE siege_statut NOT IN (1, 2, 3);

        ALTER TABLE fandrio_app.sieges_reserves
            ADD CONSTRAINT sieges_reserves_siege_statut_check
            CHECK (siege_statut IN (1, 2, 3));

        RAISE NOTICE 'CHECK constraint ajouté sur sieges_reserves.siege_statut';
    ELSE
        RAISE NOTICE 'CHECK constraint déjà présent sur sieges_reserves.siege_statut';
    END IF;
END $$;
