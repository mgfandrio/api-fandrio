-- =============================================================================
-- Migration : équipe des compagnies (plusieurs administrateurs)
--
-- util_admin_principal : l'admin compagnie créé par FANDRIO ; lui seul gère
--                        l'équipe (ajout, désactivation, mot de passe). Un seul
--                        par compagnie ; transférable par l'admin système.
-- util_mdp_provisoire  : mot de passe donné par un autre (ajout, réinitialisation) :
--                        à changer à la prochaine connexion, l'API refuse tout
--                        le reste en attendant.
-- Les admins existants : le plus ancien (actif ou inactif) de chaque compagnie
-- devient admin principal.
-- Idempotent.
-- =============================================================================

BEGIN;

ALTER TABLE fandrio_app.utilisateurs
    ADD COLUMN IF NOT EXISTS util_admin_principal BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS util_mdp_provisoire BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN fandrio_app.utilisateurs.util_admin_principal IS
    'Admin compagnie principal (créé par FANDRIO) : seul à gérer l''équipe. Un seul par compagnie.';
COMMENT ON COLUMN fandrio_app.utilisateurs.util_mdp_provisoire IS
    'Mot de passe provisoire à changer à la prochaine connexion (l''API refuse le reste en attendant)';

UPDATE fandrio_app.utilisateurs u
SET util_admin_principal = TRUE
WHERE u.util_id IN (
    SELECT MIN(util_id) FROM fandrio_app.utilisateurs
    WHERE util_role = 2 AND comp_id IS NOT NULL AND util_statut <> 3
    GROUP BY comp_id
)
AND NOT EXISTS (
    SELECT 1 FROM fandrio_app.utilisateurs p
    WHERE p.comp_id = u.comp_id AND p.util_admin_principal
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_utilisateurs_admin_principal_unique
    ON fandrio_app.utilisateurs (comp_id) WHERE util_admin_principal;

COMMIT;
