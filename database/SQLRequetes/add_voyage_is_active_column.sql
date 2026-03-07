-- Add voyage_is_active column to voyages table
-- This column indicates whether a voyage is active for reservations
ALTER TABLE fandrio_app.voyages ADD COLUMN voyage_is_active BOOLEAN NOT NULL DEFAULT true;
