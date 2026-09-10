-- ============================================================
--  AJOUT v7 : Courriers ne nécessitant pas de réponse
--  Script ADDITIF : aucune table existante n'est supprimée.
-- ============================================================

ALTER TABLE courriers ADD COLUMN IF NOT EXISTS reponse_requise BOOLEAN NOT NULL DEFAULT TRUE;
CREATE INDEX IF NOT EXISTS idx_courriers_reponse_requise ON courriers (reponse_requise);
