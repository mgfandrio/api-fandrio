-- =============================================================================
-- Migration: Sécurisation du numéro de référence de paiement
-- Date: 2026-03-24
-- Description: 
--   - Agrandir le champ numero_paiement (VARCHAR(20) → VARCHAR(50)) pour 
--     accueillir les références de transaction mobile money
--   - Ajouter un index unique partiel pour empêcher la réutilisation 
--     d'un même numéro de référence (exclut NULL, '', 'N/A')
-- =============================================================================

-- 1. Agrandir la colonne numero_paiement
ALTER TABLE fandrio_app.reservations 
    ALTER COLUMN numero_paiement TYPE VARCHAR(50);

-- 2. Index unique partiel sur numero_paiement (évite les doublons de références)
CREATE UNIQUE INDEX IF NOT EXISTS idx_reservations_numero_paiement_unique 
    ON fandrio_app.reservations (numero_paiement) 
    WHERE numero_paiement IS NOT NULL 
      AND numero_paiement != 'N/A' 
      AND numero_paiement != '';
