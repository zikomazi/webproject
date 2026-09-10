-- ============================================================
--  AJOUT v7 : suivi des notifications de retard
--  Script ADDITIF : aucune table existante n'est supprimée.
-- ============================================================

ALTER TABLE courriers ADD COLUMN IF NOT EXISTS retard_notifie BOOLEAN NOT NULL DEFAULT FALSE;
CREATE INDEX IF NOT EXISTS idx_courriers_retard_notifie ON courriers (retard_notifie);
