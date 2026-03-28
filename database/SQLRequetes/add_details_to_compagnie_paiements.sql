-- Ajout des colonnes pour les détails de paiement dans la table pivot compagnie_paiements
ALTER TABLE compagnie_paiements ADD COLUMN IF NOT EXISTS comp_paie_numero VARCHAR(20) DEFAULT NULL;
ALTER TABLE compagnie_paiements ADD COLUMN IF NOT EXISTS comp_paie_titulaire VARCHAR(100) DEFAULT NULL;
