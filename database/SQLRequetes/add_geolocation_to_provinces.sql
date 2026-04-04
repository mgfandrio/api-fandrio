-- =============================================================================
-- AJOUT DES COORDONNÉES GPS AUX PROVINCES
-- =============================================================================

-- Ajout des colonnes latitude et longitude
ALTER TABLE fandrio_app.provinces ADD COLUMN IF NOT EXISTS pro_latitude DECIMAL(10,7);
ALTER TABLE fandrio_app.provinces ADD COLUMN IF NOT EXISTS pro_longitude DECIMAL(10,7);

-- Coordonnées GPS des 6 provinces de Madagascar
UPDATE fandrio_app.provinces SET pro_latitude = -18.9137000, pro_longitude = 47.5361000 WHERE pro_nom = 'Antananarivo';
UPDATE fandrio_app.provinces SET pro_latitude = -19.8659000, pro_longitude = 47.0333000 WHERE pro_nom = 'Antsirabe';
UPDATE fandrio_app.provinces SET pro_latitude = -21.4426000, pro_longitude = 47.0857000 WHERE pro_nom = 'Fianarantsoa';
UPDATE fandrio_app.provinces SET pro_latitude = -18.1443000, pro_longitude = 49.3958000 WHERE pro_nom = 'Toamasina';
UPDATE fandrio_app.provinces SET pro_latitude = -15.7167000, pro_longitude = 46.3167000 WHERE pro_nom = 'Mahajanga';
UPDATE fandrio_app.provinces SET pro_latitude = -23.3516000, pro_longitude = 43.6855000 WHERE pro_nom = 'Toliara';
UPDATE fandrio_app.provinces SET pro_latitude = -12.2795000, pro_longitude = 49.2913000 WHERE pro_nom = 'Antsiranana';

-- Index pour les recherches géographiques
CREATE INDEX IF NOT EXISTS idx_provinces_geolocation ON fandrio_app.provinces(pro_latitude, pro_longitude);

-- Commentaires
COMMENT ON COLUMN fandrio_app.provinces.pro_latitude IS 'Latitude GPS du chef-lieu de la province';
COMMENT ON COLUMN fandrio_app.provinces.pro_longitude IS 'Longitude GPS du chef-lieu de la province';
