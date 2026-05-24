-- =============================================================================
-- Feature: Modes VIP & Premium pour les compagnies, trajets et voitures
-- =============================================================================
-- Une compagnie peut avoir 0, 1 ou 2 modes activés (vip et premium sont
-- indépendants). Les modes sont activés/désactivés par le super-admin (role:3).
--
-- Quand un mode est activé pour une compagnie :
--   - Elle peut créer des trajets de catégorie 'vip' ou 'premium'
--   - Elle peut créer des voitures de catégorie 'vip' ou 'premium'
--   - Un voyage qui lie un trajet 'vip' à une voiture 'vip' est automatiquement
--     considéré comme un voyage VIP (catégorie dérivée du trajet).
--
-- Règle de cohérence : la catégorie du trajet et de la voiture doivent
-- correspondre lors de la création d'un voyage (gérée côté service).
-- =============================================================================

-- 1) Compagnies : activation des modes par le super-admin
ALTER TABLE fandrio_app.compagnies
  ADD COLUMN IF NOT EXISTS comp_mode_vip BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS comp_mode_premium BOOLEAN NOT NULL DEFAULT FALSE;

-- 2) Trajets : catégorie tarifaire (le prix est déjà sur traj_tarif)
ALTER TABLE fandrio_app.trajets
  ADD COLUMN IF NOT EXISTS traj_categorie VARCHAR(20) NOT NULL DEFAULT 'classique';

ALTER TABLE fandrio_app.trajets
  DROP CONSTRAINT IF EXISTS trajets_traj_categorie_check;

ALTER TABLE fandrio_app.trajets
  ADD CONSTRAINT trajets_traj_categorie_check
  CHECK (traj_categorie IN ('classique', 'vip', 'premium'));

-- 3) Voitures : catégorie (impacte le plan de sièges généré)
ALTER TABLE fandrio_app.voitures
  ADD COLUMN IF NOT EXISTS voit_categorie VARCHAR(20) NOT NULL DEFAULT 'classique';

ALTER TABLE fandrio_app.voitures
  DROP CONSTRAINT IF EXISTS voitures_voit_categorie_check;

ALTER TABLE fandrio_app.voitures
  ADD CONSTRAINT voitures_voit_categorie_check
  CHECK (voit_categorie IN ('classique', 'vip', 'premium'));

-- 4) Index pour filtrer rapidement par catégorie côté client
CREATE INDEX IF NOT EXISTS idx_trajets_categorie ON fandrio_app.trajets(traj_categorie);
CREATE INDEX IF NOT EXISTS idx_voitures_categorie ON fandrio_app.voitures(voit_categorie);
