-- =============================================================================
-- Migration : identifiants PAPI par compagnie (flux billet client → compagnie)
-- =============================================================================
-- La compagnie encaisse ses billets sur SON compte PAPI. Fandrio stocke sa clé
-- API (et son secret webhook si nécessaire) pour créer les liens de paiement et
-- vérifier les notifications. Saisie/modification UNIQUEMENT par le super-admin.
--
-- Sécurité : comp_papi_api_key et comp_papi_webhook_secret sont chiffrés au
-- niveau applicatif (cast Laravel `encrypted`, APP_KEY) → stockés en TEXT
-- (le chiffré est plus long que la valeur brute) et JAMAIS renvoyés par l'API.
-- =============================================================================

ALTER TABLE fandrio_app.compagnies
    ADD COLUMN IF NOT EXISTS comp_papi_api_key TEXT NULL,
    ADD COLUMN IF NOT EXISTS comp_papi_webhook_secret TEXT NULL,
    ADD COLUMN IF NOT EXISTS comp_papi_actif BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN fandrio_app.compagnies.comp_papi_api_key IS
    'Clé API PAPI de la compagnie (chiffrée au niveau applicatif). Jamais exposée par l''API.';
COMMENT ON COLUMN fandrio_app.compagnies.comp_papi_webhook_secret IS
    'Secret de signature des webhooks PAPI de la compagnie (chiffré). Nullable.';
COMMENT ON COLUMN fandrio_app.compagnies.comp_papi_actif IS
    'Active le paiement en ligne PAPI pour cette compagnie (géré par le super-admin).';
