-- =============================================================================
-- Ajout de la table failed_jobs (jobs de queue Laravel en échec)
-- Nécessaire depuis le passage des push notifications et des broadcasts
-- WebSocket en arrière-plan (queue Redis + conteneur "queue").
-- Format attendu par le driver "database-uuids" (config/queue.php).
-- =============================================================================

CREATE TABLE IF NOT EXISTS fandrio_app.failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
