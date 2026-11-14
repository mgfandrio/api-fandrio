-- =============================================================================
-- Migration : publication des voyages (voyage planifié non publié / publication programmée)
--
--   voyage_publie             TRUE  = publié (visible et réservable par les clients,
--                                     sauf mise en pause via voyage_is_active)
--                             FALSE = planifié mais non publié : prévision de la
--                                     compagnie, les clients ne le voient pas
--   voyage_publication_prevue instant (heure de Madagascar) auquel un voyage non publié sera publié
--                             automatiquement par la tâche `voyages:publier` ;
--                             NULL = pas de date (publication manuelle)
--
-- Un voyage non publié est toujours inactif (voyage_is_active = false) : tous les écrans
-- client filtrent déjà sur voyage_is_active, il est donc invisible partout.
-- Les voyages existants restent publiés (DEFAULT TRUE).
-- Idempotent.
-- =============================================================================

BEGIN;

ALTER TABLE fandrio_app.voyages
    ADD COLUMN IF NOT EXISTS voyage_publie BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS voyage_publication_prevue TIMESTAMP NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'fandrio_app.voyages'::regclass
          AND conname = 'voyages_non_publie_inactif'
    ) THEN
        ALTER TABLE fandrio_app.voyages
            ADD CONSTRAINT voyages_non_publie_inactif
            CHECK (voyage_publie = true OR voyage_is_active = false);
    END IF;
END $$;

-- Voyages planifiés à publier : consultés chaque minute par la tâche planifiée
CREATE INDEX IF NOT EXISTS idx_voyages_publication_prevue
    ON fandrio_app.voyages (voyage_publication_prevue)
    WHERE voyage_publie = false AND voyage_publication_prevue IS NOT NULL;

COMMENT ON COLUMN fandrio_app.voyages.voyage_publie IS
    'TRUE : publié (visible des clients). FALSE : planifié mais non publié, invisible des clients (voyage_is_active = false)';
COMMENT ON COLUMN fandrio_app.voyages.voyage_publication_prevue IS
    'Instant (heure de Madagascar) de publication automatique d''un voyage non publié ; NULL = publication manuelle';

COMMIT;
