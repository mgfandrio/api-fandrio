-- =============================================================================
-- AJOUT DE LA COLONNE comm_frequence_collecte À LA TABLE compagnies
-- Permet de définir la fréquence de collecte des commissions par compagnie
-- Valeurs possibles : 'hebdomadaire', 'mensuelle'
-- Par défaut : 'mensuelle'
-- =============================================================================

ALTER TABLE fandrio_app.compagnies
ADD COLUMN IF NOT EXISTS comm_frequence_collecte VARCHAR(20) DEFAULT 'mensuelle';

-- Contrainte de vérification sur les valeurs autorisées
ALTER TABLE fandrio_app.compagnies
ADD CONSTRAINT chk_frequence_collecte
CHECK (comm_frequence_collecte IN ('hebdomadaire', 'mensuelle'));
