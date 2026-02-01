-- =============================================================================
-- SCRIPT SQL COMPLET POUR LA BASE DE DONNÉES FANDRIO
-- Compatible avec Supabase (PostgreSQL)
-- SCHÉMA: fandrio_app
-- =============================================================================

-- Création du schéma fandrio_app s'il n'existe pas
CREATE SCHEMA IF NOT EXISTS fandrio_app;

-- Définir le search_path pour utiliser le schéma fandrio_app par défaut
SET search_path TO fandrio_app, public;

-- Suppression des tables existantes (si elles existent) dans le schéma fandrio_app
DROP TABLE IF EXISTS fandrio_app.commissions CASCADE;
DROP TABLE IF EXISTS fandrio_app.factures CASCADE;
DROP TABLE IF EXISTS fandrio_app.notifications CASCADE;
DROP TABLE IF EXISTS fandrio_app.paiements CASCADE;
DROP TABLE IF EXISTS fandrio_app.reservation_voyageurs CASCADE;
DROP TABLE IF EXISTS fandrio_app.reservations CASCADE;
DROP TABLE IF EXISTS fandrio_app.compagnie_paiements CASCADE;
DROP TABLE IF EXISTS fandrio_app.types_paiement CASCADE;
DROP TABLE IF EXISTS fandrio_app.voyages CASCADE;
DROP TABLE IF EXISTS fandrio_app.trajets CASCADE;
DROP TABLE IF EXISTS fandrio_app.compagnie_provinces CASCADE;
DROP TABLE IF EXISTS fandrio_app.voitures CASCADE;
DROP TABLE IF EXISTS fandrio_app.chauffeurs CASCADE;
DROP TABLE IF EXISTS fandrio_app.voyageurs CASCADE;
DROP TABLE IF EXISTS fandrio_app.compagnies CASCADE;
DROP TABLE IF EXISTS fandrio_app.utilisateurs CASCADE;
DROP TABLE IF EXISTS fandrio_app.provinces CASCADE;

-- =============================================================================
-- 1. TABLE PROVINCES
-- =============================================================================
CREATE TABLE fandrio_app.provinces (
    pro_id SERIAL PRIMARY KEY,
    pro_nom VARCHAR(100) NOT NULL UNIQUE,
    pro_orientation VARCHAR(20) CHECK (pro_orientation IN ('Nord', 'Sud', 'Est', 'Ouest', 'Centre')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 2. TABLE COMPAGNIES
-- =============================================================================
CREATE TABLE fandrio_app.compagnies (
    comp_id SERIAL PRIMARY KEY,
    comp_nom VARCHAR(200) NOT NULL,
    comp_statut INTEGER DEFAULT 1 CHECK (comp_statut IN (1, 2, 3)), -- 1: actif, 2: inactif, 3: supprimé
    comp_nif VARCHAR(50),
    comp_stat VARCHAR(50),
    comp_logo TEXT, -- Base64 ou URL
    comp_description TEXT,
    comp_phone VARCHAR(20),
    comp_email VARCHAR(100),
    comp_adresse TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 3. TABLE UTILISATEURS
-- =============================================================================
CREATE TABLE fandrio_app.utilisateurs (
    util_id SERIAL PRIMARY KEY,
    util_nom VARCHAR(100) NOT NULL,
    util_prenom VARCHAR(100) NOT NULL,
    util_anniv DATE,
    util_email VARCHAR(150) UNIQUE NOT NULL,
    util_phone VARCHAR(20) UNIQUE NOT NULL,
    util_role INTEGER DEFAULT 1 CHECK (util_role IN (1, 2, 3)), -- 1: utilisateur, 2: admin compagnie, 3: admin app
    util_photo TEXT, -- Base64
    util_password VARCHAR(255) NOT NULL,
    util_statut INTEGER DEFAULT 1 CHECK (util_statut IN (1, 2, 3)), -- 1: actif, 2: inactif, 3: supprimé
    comp_id INTEGER REFERENCES fandrio_app.compagnies(comp_id) ON DELETE SET NULL, -- NULL pour utilisateurs normaux
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 4. TABLE VOYAGEURS
-- =============================================================================
CREATE TABLE fandrio_app.voyageurs (
    voya_id SERIAL PRIMARY KEY,
    voya_nom VARCHAR(100) NOT NULL,
    voya_prenom VARCHAR(100) NOT NULL,
    voya_age INTEGER CHECK (voya_age > 0 AND voya_age < 150),
    voya_cin VARCHAR(20) UNIQUE,
    voya_phone VARCHAR(20),
    voya_phone2 VARCHAR(20),
    util_id INTEGER NOT NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 5. TABLE CHAUFFEURS
-- =============================================================================
CREATE TABLE fandrio_app.chauffeurs (
    chauff_id SERIAL PRIMARY KEY,
    chauff_nom VARCHAR(100) NOT NULL,
    chauff_prenom VARCHAR(100) NOT NULL,
    chauff_age INTEGER CHECK (chauff_age >= 18 AND chauff_age < 80),
    chauff_cin VARCHAR(20) UNIQUE NOT NULL,
    chauff_permis VARCHAR(10) CHECK (chauff_permis IN ('A', 'B', 'C', 'D')) NOT NULL,
    chauff_phone VARCHAR(20) NOT NULL,
    chauff_statut INTEGER DEFAULT 1 CHECK (chauff_statut IN (1, 2, 3)), -- 1: actif, 2: inactif, 3: supprimé
    chauff_photo TEXT, -- Base64
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 6. TABLE VOITURES
-- =============================================================================
CREATE TABLE fandrio_app.voitures (
    voit_id SERIAL PRIMARY KEY,
    voit_matricule VARCHAR(20) UNIQUE NOT NULL,
    voit_marque VARCHAR(50) NOT NULL,
    voit_modele VARCHAR(50),
    voit_places INTEGER NOT NULL CHECK (voit_places > 0 AND voit_places <= 100),
    voit_statut INTEGER DEFAULT 1 CHECK (voit_statut IN (1, 2, 3)), -- 1: actif, 2: inactif, 3: supprimé
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    chauff_id INTEGER REFERENCES fandrio_app.chauffeurs(chauff_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 7. TABLE COMPAGNIE_PROVINCES (Liaison Many-to-Many)
-- =============================================================================
CREATE TABLE fandrio_app.compagnie_provinces (
    comp_pro_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    pro_id INTEGER NOT NULL REFERENCES fandrio_app.provinces(pro_id) ON DELETE CASCADE,
    comp_pro_statut INTEGER DEFAULT 1 CHECK (comp_pro_statut IN (1, 2)), -- 1: actif, 2: inactif
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(comp_id, pro_id)
);

-- =============================================================================
-- 8. TABLE TRAJETS
-- =============================================================================
CREATE TABLE fandrio_app.trajets (
    traj_id SERIAL PRIMARY KEY,
    traj_nom VARCHAR(200) NOT NULL,
    pro_depart INTEGER NOT NULL REFERENCES fandrio_app.provinces(pro_id),
    pro_arrivee INTEGER NOT NULL REFERENCES fandrio_app.provinces(pro_id),
    traj_tarif DECIMAL(10,2) NOT NULL CHECK (traj_tarif > 0),
    traj_km INTEGER CHECK (traj_km > 0),
    traj_duree INTERVAL, -- Durée du trajet (ex: '5 hours 30 minutes')
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    traj_statut INTEGER DEFAULT 1 CHECK (traj_statut IN (1, 2)), -- 1: actif, 2: inactif
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (pro_depart != pro_arrivee)
);

-- =============================================================================
-- 9. TABLE VOYAGES (Planning des voyages)
-- =============================================================================
CREATE TABLE fandrio_app.voyages (
    voyage_id SERIAL PRIMARY KEY,
    voyage_date DATE NOT NULL,
    voyage_heure_depart TIME NOT NULL,
    voyage_type INTEGER DEFAULT 1 CHECK (voyage_type IN (1, 2)), -- 1: jour, 2: nuit
    traj_id INTEGER NOT NULL REFERENCES fandrio_app.trajets(traj_id) ON DELETE CASCADE,
    voit_id INTEGER NOT NULL REFERENCES fandrio_app.voitures(voit_id),
    voyage_statut INTEGER DEFAULT 1 CHECK (voyage_statut IN (1, 2, 3, 4)), -- 1: programmé, 2: en cours, 3: terminé, 4: annulé
    places_disponibles INTEGER NOT NULL,
    places_reservees INTEGER DEFAULT 0 CHECK (places_reservees >= 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (places_reservees <= places_disponibles),
    CHECK (voyage_date >= CURRENT_DATE)
);

-- =============================================================================
-- 10. TABLE TYPES_PAIEMENT
-- =============================================================================
CREATE TABLE fandrio_app.types_paiement (
    type_paie_id SERIAL PRIMARY KEY,
    type_paie_nom VARCHAR(50) NOT NULL UNIQUE, -- Orange Money, MVola, Airtel Money, Cash
    type_paie_type INTEGER NOT NULL CHECK (type_paie_type IN (1, 2)), -- 1: mobile money, 2: cash
    type_paie_devise VARCHAR(5) DEFAULT 'AR',
    type_paie_statut INTEGER DEFAULT 1 CHECK (type_paie_statut IN (1, 2)), -- 1: actif, 2: inactif
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 11. TABLE COMPAGNIE_PAIEMENTS (Liaison Many-to-Many)
-- =============================================================================
CREATE TABLE fandrio_app.compagnie_paiements (
    comp_paie_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    type_paie_id INTEGER NOT NULL REFERENCES fandrio_app.types_paiement(type_paie_id) ON DELETE CASCADE,
    comp_paie_statut INTEGER DEFAULT 1 CHECK (comp_paie_statut IN (1, 2)), -- 1: actif, 2: inactif
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(comp_id, type_paie_id)
);

-- =============================================================================
-- 12. TABLE RESERVATIONS
-- =============================================================================
CREATE TABLE fandrio_app.reservations (
    res_id SERIAL PRIMARY KEY,
    res_numero VARCHAR(20) UNIQUE NOT NULL,
    util_id INTEGER NOT NULL REFERENCES fandrio_app.utilisateurs(util_id) ON DELETE CASCADE,
    voyage_id INTEGER NOT NULL REFERENCES fandrio_app.voyages(voyage_id) ON DELETE CASCADE,
    res_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    res_statut INTEGER DEFAULT 1 CHECK (res_statut IN (1, 2, 3, 4)), -- 1: en attente, 2: confirmée, 3: payée, 4: annulée
    nb_voyageurs INTEGER NOT NULL CHECK (nb_voyageurs > 0),
    montant_total DECIMAL(10,2) NOT NULL CHECK (montant_total > 0),
    montant_avance DECIMAL(10,2) DEFAULT 0 CHECK (montant_avance >= 0),
    montant_restant DECIMAL(10,2) DEFAULT 0 CHECK (montant_restant >= 0),
    type_paie_id INTEGER NOT NULL REFERENCES fandrio_app.types_paiement(type_paie_id),
    numero_paiement VARCHAR(20), -- Numéro de téléphone si mobile money
    date_limite_paiement TIMESTAMP,
    date_annulation_possible TIMESTAMP, -- 3 jours avant le voyage
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (montant_avance <= montant_total),
    CHECK (montant_restant = montant_total - montant_avance)
);

-- =============================================================================
-- 13. TABLE RESERVATION_VOYAGEURS (Liaison avec détails des places)
-- =============================================================================
CREATE TABLE fandrio_app.reservation_voyageurs (
    res_voya_id SERIAL PRIMARY KEY,
    res_id INTEGER NOT NULL REFERENCES fandrio_app.reservations(res_id) ON DELETE CASCADE,
    voya_id INTEGER NOT NULL REFERENCES fandrio_app.voyageurs(voya_id) ON DELETE CASCADE,
    place_numero INTEGER NOT NULL CHECK (place_numero > 0),
    res_voya_statut INTEGER DEFAULT 1 CHECK (res_voya_statut IN (1, 2)), -- 1: confirmé, 2: annulé
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(res_id, place_numero) -- Une place par réservation
);

-- =============================================================================
-- 14. TABLE PAIEMENTS (Historique des transactions)
-- =============================================================================
CREATE TABLE fandrio_app.paiements (
    paie_id SERIAL PRIMARY KEY,
    res_id INTEGER NOT NULL REFERENCES fandrio_app.reservations(res_id) ON DELETE CASCADE,
    paie_montant DECIMAL(10,2) NOT NULL CHECK (paie_montant > 0),
    paie_type INTEGER NOT NULL CHECK (paie_type IN (1, 2, 3)), -- 1: avance, 2: solde, 3: remboursement
    paie_statut INTEGER DEFAULT 1 CHECK (paie_statut IN (1, 2, 3)), -- 1: en attente, 2: validé, 3: échoué
    paie_reference VARCHAR(100), -- Référence transaction mobile money
    paie_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    type_paie_id INTEGER NOT NULL REFERENCES fandrio_app.types_paiement(type_paie_id),
    paie_commentaire TEXT
);

-- =============================================================================
-- 15. TABLE FACTURES
-- =============================================================================
CREATE TABLE fandrio_app.factures (
    fact_id SERIAL PRIMARY KEY,
    fact_numero VARCHAR(20) UNIQUE NOT NULL,
    res_id INTEGER NOT NULL REFERENCES fandrio_app.reservations(res_id) ON DELETE CASCADE,
    fact_date_emission TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fact_montant_ht DECIMAL(10,2) NOT NULL,
    fact_tva DECIMAL(10,2) DEFAULT 0,
    fact_montant_ttc DECIMAL(10,2) NOT NULL,
    fact_statut INTEGER DEFAULT 1 CHECK (fact_statut IN (1, 2, 3)), -- 1: émise, 2: payée, 3: annulée
    fact_contenu JSONB -- Détails de la facture en JSON
);

-- =============================================================================
-- 16. TABLE NOTIFICATIONS
-- =============================================================================
CREATE TABLE fandrio_app.notifications (
    notif_id SERIAL PRIMARY KEY,
    notif_type INTEGER NOT NULL CHECK (notif_type IN (1, 2, 3, 4)), -- 1: confirmation, 2: rappel, 3: annulation, 4: nouvelle réservation
    notif_destinataire_type INTEGER NOT NULL CHECK (notif_destinataire_type IN (1, 2, 3)), -- 1: utilisateur, 2: compagnie, 3: admin
    notif_destinataire_id INTEGER NOT NULL,
    notif_titre VARCHAR(200) NOT NULL,
    notif_message TEXT NOT NULL,
    notif_date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notif_statut INTEGER DEFAULT 1 CHECK (notif_statut IN (1, 2, 3)), -- 1: en attente, 2: envoyée, 3: lue
    res_id INTEGER REFERENCES fandrio_app.reservations(res_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- 17. TABLE COMMISSIONS (Modèle économique)
-- =============================================================================
CREATE TABLE fandrio_app.commissions (
    comm_id SERIAL PRIMARY KEY,
    comp_id INTEGER NOT NULL REFERENCES fandrio_app.compagnies(comp_id) ON DELETE CASCADE,
    comm_periode VARCHAR(7) NOT NULL, -- Format YYYY-MM
    nb_reservations INTEGER DEFAULT 0,
    nb_groupes_5 INTEGER DEFAULT 0, -- Nombre de groupes de 5 réservations
    comm_taux DECIMAL(5,2) DEFAULT 5.00, -- 5% par défaut
    comm_montant DECIMAL(10,2) DEFAULT 0,
    comm_statut INTEGER DEFAULT 1 CHECK (comm_statut IN (1, 2, 3)), -- 1: calculée, 2: facturée, 3: payée
    date_calcul TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(comp_id, comm_periode)
);

-- =============================================================================
-- CRÉATION DES INDEX POUR OPTIMISER LES PERFORMANCES
-- =============================================================================

-- Index sur les colonnes fréquemment utilisées
CREATE INDEX idx_utilisateurs_email ON fandrio_app.utilisateurs(util_email);
CREATE INDEX idx_utilisateurs_role ON fandrio_app.utilisateurs(util_role);
CREATE INDEX idx_reservations_numero ON fandrio_app.reservations(res_numero);
CREATE INDEX idx_reservations_statut ON fandrio_app.reservations(res_statut);
CREATE INDEX idx_voyages_date ON fandrio_app.voyages(voyage_date);
CREATE INDEX idx_voyages_statut ON fandrio_app.voyages(voyage_statut);
CREATE INDEX idx_trajets_compagnie ON fandrio_app.trajets(comp_id);
CREATE INDEX idx_trajets_provinces ON fandrio_app.trajets(pro_depart, pro_arrivee);
CREATE INDEX idx_notifications_destinataire ON fandrio_app.notifications(notif_destinataire_type, notif_destinataire_id);
CREATE INDEX idx_paiements_statut ON fandrio_app.paiements(paie_statut);
CREATE INDEX idx_voitures_statut ON fandrio_app.voitures(voit_statut);
CREATE INDEX idx_chauffeurs_statut ON fandrio_app.chauffeurs(chauff_statut);

-- =============================================================================
-- TRIGGERS POUR MISE À JOUR AUTOMATIQUE DES TIMESTAMPS
-- =============================================================================

-- Fonction générique pour updated_at
CREATE OR REPLACE FUNCTION fandrio_app.update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Application des triggers
CREATE TRIGGER update_utilisateurs_updated_at BEFORE UPDATE ON fandrio_app.utilisateurs FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_compagnies_updated_at BEFORE UPDATE ON fandrio_app.compagnies FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_chauffeurs_updated_at BEFORE UPDATE ON fandrio_app.chauffeurs FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_voitures_updated_at BEFORE UPDATE ON fandrio_app.voitures FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_trajets_updated_at BEFORE UPDATE ON fandrio_app.trajets FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_voyages_updated_at BEFORE UPDATE ON fandrio_app.voyages FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
CREATE TRIGGER update_reservations_updated_at BEFORE UPDATE ON fandrio_app.reservations FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();

-- =============================================================================
-- TRIGGER POUR GÉNÉRATION AUTOMATIQUE DU NUMÉRO DE RÉSERVATION
-- =============================================================================

CREATE OR REPLACE FUNCTION fandrio_app.generate_reservation_number()
RETURNS TRIGGER AS $$
BEGIN
    NEW.res_numero = 'FAN' || TO_CHAR(CURRENT_DATE, 'YYYYMMDD') || LPAD(NEW.res_id::text, 4, '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_generate_reservation_number
    BEFORE INSERT ON fandrio_app.reservations
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.generate_reservation_number();

-- =============================================================================
-- TRIGGER POUR GÉNÉRATION AUTOMATIQUE DU NUMÉRO DE FACTURE
-- =============================================================================

CREATE OR REPLACE FUNCTION fandrio_app.generate_facture_number()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fact_numero = 'FACT' || TO_CHAR(CURRENT_DATE, 'YYYYMMDD') || LPAD(NEW.fact_id::text, 4, '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_generate_facture_number
    BEFORE INSERT ON fandrio_app.factures
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.generate_facture_number();

-- =============================================================================
-- TRIGGER POUR MISE À JOUR DES PLACES DISPONIBLES
-- =============================================================================

CREATE OR REPLACE FUNCTION fandrio_app.update_places_voyage()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- Diminuer les places disponibles lors d'une nouvelle réservation
        UPDATE fandrio_app.voyages 
        SET places_reservees = places_reservees + NEW.nb_voyageurs
        WHERE voyage_id = NEW.voyage_id;
        RETURN NEW;
    ELSIF TG_OP = 'UPDATE' THEN
        -- Mise à jour si le statut change
        IF OLD.res_statut != NEW.res_statut THEN
            IF NEW.res_statut = 4 THEN -- Annulation
                UPDATE fandrio_app.voyages 
                SET places_reservees = places_reservees - NEW.nb_voyageurs
                WHERE voyage_id = NEW.voyage_id;
            END IF;
        END IF;
        RETURN NEW;
    ELSIF TG_OP = 'DELETE' THEN
        -- Libérer les places lors de la suppression
        UPDATE fandrio_app.voyages 
        SET places_reservees = places_reservees - OLD.nb_voyageurs
        WHERE voyage_id = OLD.voyage_id;
        RETURN OLD;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_update_places_voyage
    AFTER INSERT OR UPDATE OR DELETE ON fandrio_app.reservations
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_places_voyage();

-- =============================================================================
-- DONNÉES INITIALES (OPTIONNEL)
-- =============================================================================

-- Insertion des provinces de Madagascar
INSERT INTO fandrio_app.provinces (pro_nom, pro_orientation) VALUES
('Antananarivo', 'Centre'),
('Fianarantsoa', 'Sud'),
('Toamasina', 'Est'),
('Mahajanga', 'Ouest'),
('Toliara', 'Sud'),
('Antsiranana', 'Nord');

-- Insertion des types de paiement par défaut
INSERT INTO fandrio_app.types_paiement (type_paie_nom, type_paie_type) VALUES
('Orange Money', 1),
('MVola', 1),
('Airtel Money', 1),
('Cash', 2);

-- Création de l'utilisateur administrateur par défaut
INSERT INTO fandrio_app.utilisateurs (util_nom, util_prenom, util_email, util_phone, util_role, util_password) VALUES
('Admin', 'System', 'admin@fandrio.mg', '+261340000000', 3, '$2y$12$rNwKb.KvoP/4N20XBQ4TzO2yZn1WD/zAcagiiJDmSJTgW3MxUrk1q'); -- password

-- =============================================================================
-- VUES UTILES POUR L'APPLICATION
-- =============================================================================

-- Vue des voyages avec informations complètes
CREATE VIEW fandrio_app.v_voyages_complets AS
SELECT 
    v.voyage_id,
    v.voyage_date,
    v.voyage_heure_depart,
    v.voyage_type,
    v.places_disponibles,
    v.places_reservees,
    (v.places_disponibles - v.places_reservees) as places_libres,
    t.traj_nom,
    t.traj_tarif,
    t.traj_km,
    t.traj_duree,
    pd.pro_nom as province_depart,
    pa.pro_nom as province_arrivee,
    c.comp_nom,
    c.comp_logo,
    vo.voit_matricule,
    vo.voit_places,
    ch.chauff_nom,
    ch.chauff_prenom
FROM fandrio_app.voyages v
JOIN fandrio_app.trajets t ON v.traj_id = t.traj_id
JOIN fandrio_app.provinces pd ON t.pro_depart = pd.pro_id
JOIN fandrio_app.provinces pa ON t.pro_arrivee = pa.pro_id
JOIN fandrio_app.compagnies c ON t.comp_id = c.comp_id
JOIN fandrio_app.voitures vo ON v.voit_id = vo.voit_id
LEFT JOIN fandrio_app.chauffeurs ch ON vo.chauff_id = ch.chauff_id
WHERE v.voyage_statut = 1 AND c.comp_statut = 1;

-- Vue des réservations avec détails
CREATE VIEW fandrio_app.v_reservations_details AS
SELECT 
    r.res_id,
    r.res_numero,
    r.res_date,
    r.res_statut,
    r.nb_voyageurs,
    r.montant_total,
    r.montant_avance,
    r.montant_restant,
    u.util_nom,
    u.util_prenom,
    u.util_email,
    u.util_phone,
    v.voyage_date,
    v.voyage_heure_depart,
    t.traj_nom,
    c.comp_nom,
    tp.type_paie_nom
FROM fandrio_app.reservations r
JOIN fandrio_app.utilisateurs u ON r.util_id = u.util_id
JOIN fandrio_app.voyages v ON r.voyage_id = v.voyage_id
JOIN fandrio_app.trajets t ON v.traj_id = t.traj_id
JOIN fandrio_app.compagnies c ON t.comp_id = c.comp_id
JOIN fandrio_app.types_paiement tp ON r.type_paie_id = tp.type_paie_id;

-- =============================================================================
-- COMMENTAIRES SUR LES TABLES
-- =============================================================================

COMMENT ON TABLE fandrio_app.utilisateurs IS 'Table des utilisateurs de l''application (clients, admins compagnies, admin système)';
COMMENT ON TABLE fandrio_app.compagnies IS 'Table des compagnies de transport';
COMMENT ON TABLE fandrio_app.voyageurs IS 'Table des voyageurs (passagers des réservations)';
COMMENT ON TABLE fandrio_app.chauffeurs IS 'Table des chauffeurs des compagnies';
COMMENT ON TABLE fandrio_app.voitures IS 'Table des véhicules des compagnies';
COMMENT ON TABLE fandrio_app.provinces IS 'Table des provinces de Madagascar';
COMMENT ON TABLE fandrio_app.trajets IS 'Table des trajets proposés par les compagnies';
COMMENT ON TABLE fandrio_app.voyages IS 'Table des voyages programmés (planning)';
COMMENT ON TABLE fandrio_app.reservations IS 'Table des réservations effectuées';
COMMENT ON TABLE fandrio_app.paiements IS 'Table de l''historique des paiements';
COMMENT ON TABLE fandrio_app.notifications IS 'Table des notifications système';
COMMENT ON TABLE fandrio_app.commissions IS 'Table pour le calcul des commissions (modèle économique)';

-- =============================================================================
-- FIN DU SCRIPT
-- =============================================================================