-- =============================================================================
-- Migration : Invariant SQL voyages.voyage_is_active vs voyage_statut
-- Règle : un voyage actif (ouvert aux réservations) doit être en statut 1 ou 2.
-- Termine (3) ou annulé (4) ⇒ forcément inactif.
-- Idempotent : nettoie les données incohérentes avant de poser la contrainte.
-- =============================================================================

DO $$
DECLARE
    v_fixed INTEGER;
BEGIN
    -- 1. Nettoyer les éventuelles incohérences (voyage terminé/annulé encore actif)
    UPDATE fandrio_app.voyages
       SET voyage_is_active = false
     WHERE voyage_is_active = true
       AND voyage_statut IN (3, 4);

    GET DIAGNOSTICS v_fixed = ROW_COUNT;
    IF v_fixed > 0 THEN
        RAISE NOTICE '% voyage(s) incohérent(s) corrigé(s) (statut 3 ou 4 mais is_active=true).', v_fixed;
    END IF;

    -- 2. Poser la contrainte si absente (idempotent)
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'voyages_active_statut_invariant'
          AND conrelid = 'fandrio_app.voyages'::regclass
    ) THEN
        ALTER TABLE fandrio_app.voyages
            ADD CONSTRAINT voyages_active_statut_invariant
            CHECK (voyage_is_active = false OR voyage_statut IN (1, 2));
        RAISE NOTICE 'Invariant voyages_active_statut_invariant ajouté.';
    ELSE
        RAISE NOTICE 'Invariant voyages_active_statut_invariant déjà présent.';
    END IF;
END $$;
