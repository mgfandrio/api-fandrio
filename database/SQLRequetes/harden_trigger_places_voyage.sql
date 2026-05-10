-- =============================================================================
-- DURCISSEMENT du trigger trigger_update_places_voyage
--
-- RÈGLE STRICTE :
--   Une réservation occupe une place si et seulement si res_statut IN (1, 2)
--     1 = en attente (lock siège, paiement en cours)
--     2 = confirmée (payée)
--     3 = payée/voyagée
--   Statuts 4 (annulée) et 5 (abandonnée) NE comptent PAS.
--
-- CAS GÉRÉS :
--   ✅ INSERT  : si nouveau statut "actif" (1,2,3)         → +nb_voyageurs
--   ✅ UPDATE  : actif → inactif                            → -nb_voyageurs
--   ✅ UPDATE  : inactif → actif (réactivation rare)        → +nb_voyageurs
--   ✅ UPDATE  : changement de nb_voyageurs (statut actif)  → ajuster delta
--   ✅ UPDATE  : changement de voyage_id (transfert)        → ré-équilibrer 2 voyages
--   ✅ DELETE  : si statut était "actif"                    → -nb_voyageurs
-- =============================================================================

CREATE OR REPLACE FUNCTION fandrio_app.update_places_voyage()
RETURNS TRIGGER AS $$
DECLARE
    statuts_actifs INTEGER[] := ARRAY[1, 2];
    old_actif BOOLEAN;
    new_actif BOOLEAN;
BEGIN
    -- ─────────────────────────── INSERT ───────────────────────────
    IF TG_OP = 'INSERT' THEN
        IF NEW.res_statut = ANY(statuts_actifs) THEN
            UPDATE fandrio_app.voyages
            SET places_reservees = places_reservees + NEW.nb_voyageurs
            WHERE voyage_id = NEW.voyage_id;
        END IF;
        RETURN NEW;

    -- ─────────────────────────── UPDATE ───────────────────────────
    ELSIF TG_OP = 'UPDATE' THEN
        old_actif := OLD.res_statut = ANY(statuts_actifs);
        new_actif := NEW.res_statut = ANY(statuts_actifs);

        -- Cas 1 : transfert vers un autre voyage (rare mais possible)
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

        -- Cas 2 : même voyage — gérer transitions de statut et changement de nb_voyageurs
        IF old_actif AND NOT new_actif THEN
            -- Actif → Inactif (annulation, abandon)
            UPDATE fandrio_app.voyages
            SET places_reservees = GREATEST(0, places_reservees - OLD.nb_voyageurs)
            WHERE voyage_id = NEW.voyage_id;

        ELSIF NOT old_actif AND new_actif THEN
            -- Inactif → Actif (réactivation)
            UPDATE fandrio_app.voyages
            SET places_reservees = places_reservees + NEW.nb_voyageurs
            WHERE voyage_id = NEW.voyage_id;

        ELSIF old_actif AND new_actif AND OLD.nb_voyageurs != NEW.nb_voyageurs THEN
            -- Reste actif, mais nb_voyageurs modifié — appliquer le delta
            UPDATE fandrio_app.voyages
            SET places_reservees = GREATEST(0, places_reservees + (NEW.nb_voyageurs - OLD.nb_voyageurs))
            WHERE voyage_id = NEW.voyage_id;
        END IF;
        -- (autres cas : inactif → inactif, ou actif sans changement → rien à faire)
        RETURN NEW;

    -- ─────────────────────────── DELETE ───────────────────────────
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

-- Le trigger lui-même reste inchangé (on remplace juste la fonction)
-- mais on s'assure qu'il pointe bien sur la nouvelle version :
DROP TRIGGER IF EXISTS trigger_update_places_voyage ON fandrio_app.reservations;
CREATE TRIGGER trigger_update_places_voyage
    AFTER INSERT OR UPDATE OR DELETE ON fandrio_app.reservations
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_places_voyage();

COMMENT ON FUNCTION fandrio_app.update_places_voyage IS
    'Maintient places_reservees cohérent. Statuts actifs = (1,2,3). Gère INSERT/UPDATE/DELETE, transitions de statut, modifications de nb_voyageurs et transferts entre voyages.';
