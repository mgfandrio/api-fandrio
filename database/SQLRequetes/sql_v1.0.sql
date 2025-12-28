-- =============================================================================
-- 18. TABLE AUDIT_PLACES (Audit des modifications de places)
-- =============================================================================
CREATE TABLE fandrio_app.audit_places (
    audit_id SERIAL PRIMARY KEY,
    voyage_id INTEGER NOT NULL,
    anciennes_places INTEGER NOT NULL,
    nouvelles_places INTEGER NOT NULL,
    operation VARCHAR(50) NOT NULL CHECK (operation IN ('reservation', 'annulation', 'modification')),
    utilisateur_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Clé étrangère vers voyages
    CONSTRAINT fk_audit_voyage 
        FOREIGN KEY (voyage_id) 
        REFERENCES fandrio_app.voyages(voyage_id) 
        ON DELETE CASCADE,
    
    -- Clé étrangère vers utilisateurs
    CONSTRAINT fk_audit_utilisateur 
        FOREIGN KEY (utilisateur_id) 
        REFERENCES fandrio_app.utilisateurs(util_id) 
        ON DELETE SET NULL
);

-- Index pour optimiser les performances
CREATE INDEX idx_audit_places_voyage ON fandrio_app.audit_places(voyage_id);
CREATE INDEX idx_audit_places_created_at ON fandrio_app.audit_places(created_at);
CREATE INDEX idx_audit_places_utilisateur ON fandrio_app.audit_places(utilisateur_id);

-- Commentaire sur la table
COMMENT ON TABLE fandrio_app.audit_places IS 'Table d''audit pour tracer les modifications du nombre de places des voyages';


-- =============================================================================
-- 19. TABLE PLAN_SIEGES (Configuration des plans de sièges)
-- =============================================================================
CREATE TABLE fandrio_app.plans_sieges (
    plan_id SERIAL PRIMARY KEY,
    voit_id INTEGER NOT NULL REFERENCES fandrio_app.voitures(voit_id),
    config_sieges JSONB NOT NULL, -- Configuration des sièges
    plan_nom VARCHAR(100),
    plan_statut INTEGER DEFAULT 1, -- 1: actif, 2: inactif
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(voit_id)
);

-- Index pour performances
CREATE INDEX idx_plans_sieges_voiture ON fandrio_app.plans_sieges(voit_id);
CREATE INDEX idx_plans_sieges_config ON fandrio_app.plans_sieges USING GIN(config_sieges);


-- =============================================================================
-- 19. TABLE SIEGES_RESERVES (Configuration des sièges réservés)
-- =============================================================================
CREATE TABLE fandrio_app.sieges_reserves (
    siege_id SERIAL PRIMARY KEY,
    voyage_id INTEGER NOT NULL REFERENCES fandrio_app.voyages(voyage_id),
    siege_numero VARCHAR(10) NOT NULL, -- Ex: "A1", "B3", "C12"
    res_id INTEGER REFERENCES fandrio_app.reservations(res_id) ON DELETE SET NULL,
    siege_statut INTEGER DEFAULT 1, -- 1: réservé, 2: disponible, 3: sélectionné temporairement
    utilisateur_id INTEGER, -- Pour lock temporaire
    expire_lock TIMESTAMP, -- Expiration du lock temporaire
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(voyage_id, siege_numero)
);

-- Index pour performances
CREATE INDEX idx_sieges_voyage ON fandrio_app.sieges_reserves(voyage_id);
CREATE INDEX idx_sieges_reservation ON fandrio_app.sieges_reserves(res_id);
CREATE INDEX idx_sieges_lock ON fandrio_app.sieges_reserves(expire_lock) WHERE siege_statut = 3;