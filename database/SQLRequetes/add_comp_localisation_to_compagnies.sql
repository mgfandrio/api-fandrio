-- =============================================================================
-- AJOUT DE LA COLONNE LOCALISATION À LA TABLE COMPAGNIES
-- =============================================================================

-- Ajout de la colonne comp_localisation (ID de la province)
ALTER TABLE fandrio_app.compagnies 
ADD COLUMN IF NOT EXISTS comp_localisation INTEGER REFERENCES fandrio_app.provinces(pro_id) ON DELETE SET NULL;

-- Index pour améliorer la performance des recherches par localisation
CREATE INDEX IF NOT EXISTS idx_compagnies_localisation ON fandrio_app.compagnies(comp_localisation);

-- Commentaire descriptif
COMMENT ON COLUMN fandrio_app.compagnies.comp_localisation IS 'ID de la province qui sert de localisation principale à la compagnie';
