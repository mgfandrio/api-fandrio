-- =============================================================================
-- Ajout de la colonne push_token à la table utilisateurs
-- Stocke le token Expo Push pour l'envoi de notifications push
-- =============================================================================

ALTER TABLE fandrio_app.utilisateurs
    ADD COLUMN IF NOT EXISTS push_token VARCHAR(255) NULL;

COMMENT ON COLUMN fandrio_app.utilisateurs.push_token IS 'Token Expo Push notification (ExponentPushToken[...])';

CREATE INDEX IF NOT EXISTS idx_utilisateurs_push_token
    ON fandrio_app.utilisateurs(push_token)
    WHERE push_token IS NOT NULL;
