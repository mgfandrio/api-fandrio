-- =============================================================================
-- Ajout de la colonne updated_at à la table notifications
-- Eloquent (Laravel) utilise created_at + updated_at par défaut
-- =============================================================================

ALTER TABLE fandrio_app.notifications
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Initialiser updated_at = created_at pour les lignes existantes
UPDATE fandrio_app.notifications
SET updated_at = created_at
WHERE updated_at IS NULL;

-- Trigger pour mettre à jour updated_at automatiquement (réutilise la fonction existante)
DROP TRIGGER IF EXISTS update_notifications_updated_at ON fandrio_app.notifications;
CREATE TRIGGER update_notifications_updated_at
    BEFORE UPDATE ON fandrio_app.notifications
    FOR EACH ROW EXECUTE FUNCTION fandrio_app.update_updated_at_column();
