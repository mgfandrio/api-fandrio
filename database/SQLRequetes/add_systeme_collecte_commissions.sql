-- =============================================================================
-- SYSTÈME DE COLLECTE DES COMMISSIONS
-- Ajout des colonnes de planification sur compagnies + table collectes
-- =============================================================================

-- 1. Nouvelles colonnes sur compagnies
-- comm_jour_collecte : jour (lundi-dimanche) ou date (1-28) selon la fréquence
-- comm_actif : active/désactive le calcul de commission pour cette compagnie
ALTER TABLE fandrio_app.compagnies
ADD COLUMN IF NOT EXISTS comm_jour_collecte VARCHAR(20) DEFAULT NULL;

ALTER TABLE fandrio_app.compagnies
ADD COLUMN IF NOT EXISTS comm_actif BOOLEAN DEFAULT TRUE;

-- 2. Table des collectes (historique / factures)
CREATE TABLE IF NOT EXISTS fandrio_app.collectes (
    coll_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id),
    coll_periode_debut DATE NOT NULL,
    coll_periode_fin DATE NOT NULL,
    coll_montant_brut DECIMAL(12,2) NOT NULL DEFAULT 0,
    coll_montant_commission DECIMAL(12,2) NOT NULL DEFAULT 0,
    coll_taux DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    coll_nb_reservations INTEGER NOT NULL DEFAULT 0,
    coll_nb_billets INTEGER NOT NULL DEFAULT 0,
    coll_statut INTEGER NOT NULL DEFAULT 1, -- 1=en attente, 2=confirmée
    coll_date_prevue DATE NOT NULL,
    coll_date_confirmation TIMESTAMP NULL,
    coll_confirme_par INTEGER NULL REFERENCES fandrio_app.utilisateurs(util_id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(comp_id, coll_periode_debut, coll_periode_fin)
);

-- Index pour les requêtes fréquentes
CREATE INDEX IF NOT EXISTS idx_collectes_comp_id ON fandrio_app.collectes(comp_id);
CREATE INDEX IF NOT EXISTS idx_collectes_statut ON fandrio_app.collectes(coll_statut);
CREATE INDEX IF NOT EXISTS idx_collectes_date_prevue ON fandrio_app.collectes(coll_date_prevue);
