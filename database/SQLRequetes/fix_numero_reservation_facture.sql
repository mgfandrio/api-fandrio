-- =============================================================================
-- Correctif : génération des numéros de réservation et de facture
-- =============================================================================
-- Bug : LPAD(id, 4, '0') TRONQUE les identifiants de plus de 4 chiffres
--   LPAD('10000', 4) = LPAD('10009', 4) = '1000'
-- => à partir de res_id 10 000, des réservations créées le même jour recevaient
--    le même res_numero et la contrainte UNIQUE faisait échouer la réservation.
-- Correctif : compléter à 4 chiffres minimum, sans jamais tronquer.
-- Les numéros déjà attribués ne changent pas. Exécutable plusieurs fois.
-- =============================================================================

CREATE OR REPLACE FUNCTION fandrio_app.generate_reservation_number()
RETURNS TRIGGER AS $$
BEGIN
    NEW.res_numero = 'FAN' || TO_CHAR(CURRENT_DATE, 'YYYYMMDD')
        || LPAD(NEW.res_id::text, GREATEST(4, LENGTH(NEW.res_id::text)), '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION fandrio_app.generate_facture_number()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fact_numero = 'FACT' || TO_CHAR(CURRENT_DATE, 'YYYYMMDD')
        || LPAD(NEW.fact_id::text, GREATEST(4, LENGTH(NEW.fact_id::text)), '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
