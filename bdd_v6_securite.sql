-- ============================================================
--  AJOUT v6 : Sécurité renforcée
--  Script ADDITIF : aucune table existante n'est supprimée.
-- ============================================================

ALTER TABLE employes ADD COLUMN IF NOT EXISTS tentatives_echouees INTEGER NOT NULL DEFAULT 0;
ALTER TABLE employes ADD COLUMN IF NOT EXISTS verrouille_jusqua TIMESTAMP;

-- Pour la fonction "mot de passe oublié" (question secrète, sans envoi d'email)
ALTER TABLE employes ADD COLUMN IF NOT EXISTS question_secrete VARCHAR(255);
ALTER TABLE employes ADD COLUMN IF NOT EXISTS reponse_secrete_hash VARCHAR(255);

-- ===================== audit_journal =====================
-- Journal indépendant qui survit aux suppressions (contrairement à
-- historique_actions, lié par cascade au courrier et donc effacé avec lui).
-- Utilisé pour tracer les actions irréversibles : suppression de courrier,
-- suppression de compte, etc.
CREATE TABLE IF NOT EXISTS audit_journal (
    id                  BIGSERIAL PRIMARY KEY,
    action              VARCHAR(100)    NOT NULL,   -- ex: 'suppression_courrier', 'suppression_compte'
    description         TEXT            NOT NULL,
    effectue_par_id     BIGINT,                     -- pas de FK : on garde la trace même si le compte est ensuite supprimé
    effectue_par_nom    VARCHAR(200),                -- nom/prénom "figé" au moment de l'action
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_journal_date ON audit_journal (created_at DESC);

-- Empêche de notifier plusieurs fois le même retard à chaque vérification
ALTER TABLE courriers ADD COLUMN IF NOT EXISTS retard_notifie BOOLEAN NOT NULL DEFAULT FALSE;
