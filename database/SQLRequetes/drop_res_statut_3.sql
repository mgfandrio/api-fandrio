-- =============================================================================
-- Migration : Suppression du res_statut = 3 (payée) — workflow simplifié
-- Le code n'utilise plus que 4 statuts : 1, 2, 4, 5
--   1 = en attente (lock sièges, en attente saisie code paiement)
--   2 = confirmée (paiement déclaré par l'utilisateur, billet valide)
--   4 = annulée (par utilisateur ou compagnie, remboursement requis)
--   5 = abandonnée (timeout sans saisie code, sièges libérés)
--
-- Étapes :
--   1. Migrer les éventuelles réservations en statut 3 → 2
--   2. Supprimer l'ancien CHECK et en ajouter un nouveau
--   3. Redéployer le trigger update_places_voyage avec statuts_actifs = [1, 2]
--   4. Resynchroniser places_reservees pour s'assurer qu'aucun voyage ne se
--      retrouve avec un compte erroné après la migration
-- Idempotent.
-- =============================================================================

BEGIN;

-- ─── 1. Migrer les données existantes ───────────────────────────────────────
DO $$
DECLARE
    v_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_count FROM fandrio_app.reservations WHERE res_statut = 3;
    IF v_count > 0 THEN
        UPDATE fandrio_app.reservations SET res_statut = 2 WHERE res_statut = 3;
        RAISE NOTICE '% réservation(s) migrée(s) du statut 3 → 2.', v_count;
    ELSE
        RAISE NOTICE 'Aucune réservation en statut 3 à migrer.';
    END IF;
END $$;

-- ─── 2. Recréer le CHECK constraint ─────────────────────────────────────────
DO $$
DECLARE
    v_constraint_name TEXT;
BEGIN
    SELECT conname INTO v_constraint_name
    FROM pg_constraint
    WHERE conrelid = 'fandrio_app.reservations'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) ILIKE '%res_statut%';

    IF v_constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE fandrio_app.reservations DROP CONSTRAINT %I', v_constraint_name);
        RAISE NOTICE 'Ancienne contrainte % supprimée.', v_constraint_name;
    END IF;

    ALTER TABLE fandrio_app.reservations
        ADD CONSTRAINT reservations_res_statut_check
        CHECK (res_statut IN (1, 2, 4, 5));

    RAISE NOTICE 'Nouveau CHECK appliqué sur res_statut : (1, 2, 4, 5).';
END $$;

-- ─── 3. Redéployer le trigger update_places_voyage ──────────────────────────
CREATE OR REPLACE FUNCTION fandrio_app.update_places_voyage()
RETURNS TRIGGER AS $$
DECLARE
    statuts_actifs INTEGER[] := ARRAY[1, 2]; -- 1: en attente (lock), 2: confirmée (payée)
    old_actif BOOLEAN;
    new_actif BOOLEAN;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.res_statut = ANY(statuts_actifs) THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = places_reservees + NEW.nb_voyageurs
            WHERE voyage_id = NEW.voyage_id;
        END IF;
        RETURN NEW;

    ELSIF TG_OP = 'UPDATE' THEN
        old_actif := OLD.res_statut = ANY(statuts_actifs);
        new_actif := NEW.res_statut = ANY(statuts_actifs);

        IF OLD.voyage_id != NEW.voyage_id THEN
            IF old_actif THEN
                UPDATE fandrio_app.voyages
                SET places_reservees = GREATEST(0, places_reservees - OLD.nb_voyageurs)
                WHERE voyage_id = OLD.voyage_id;
            END IF;
            IF new_actif THEN
                UPDATE fandrio_app.voyages
                SET places_reservees = places_reservees + NEW.nb_voyageurs
                WHERE voyage_id = NEW.voyage_id;
            END IF;
            RETURN NEW;
        END IF;

        IF old_actif AND NOT new_actif THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = GREATEST(0, places_reservees - OLD.nb_voyageurs)
            WHERE voyage_id = NEW.voyage_id;
        ELSIF NOT old_actif AND new_actif THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = places_reservees + NEW.nb_voyageurs
            WHERE voyage_id = NEW.voyage_id;
        ELSIF old_actif AND new_actif AND OLD.nb_voyageurs != NEW.nb_voyageurs THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = GREATEST(0, places_reservees + (NEW.nb_voyageurs - OLD.nb_voyageurs))
            WHERE voyage_id = NEW.voyage_id;
        END IF;
        RETURN NEW;

    ELSIF TG_OP = 'DELETE' THEN
        IF OLD.res_statut = ANY(statuts_actifs) THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = GREATEST(0, places_reservees - OLD.nb_voyageurs)
            WHERE voyage_id = OLD.voyage_id;
        END IF;
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- ─── 4. Resync places_reservees après migration ─────────────────────────────
UPDATE fandrio_app.voyages v
SET places_reservees = COALESCE((
    SELECT SUM(r.nb_voyageurs)
    FROM fandrio_app.reservations r
    WHERE r.voyage_id = v.voyage_id
      AND r.res_statut IN (1, 2)
), 0);

COMMIT;
