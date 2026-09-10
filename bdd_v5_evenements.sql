-- ============================================================
--  AJOUT v5 : Module Calendrier / Événements importants
--  Ce script est ADDITIF : il ne supprime aucune table existante.
--  À exécuter une seule fois sur la base "suivi" déjà en place.
-- ============================================================

CREATE TABLE IF NOT EXISTS evenements (
    id                  BIGSERIAL PRIMARY KEY,
    titre               VARCHAR(255)    NOT NULL,
    description         TEXT,
    date_evenement      DATE            NOT NULL,
    heure               TIME,                              -- optionnelle
    partage             BOOLEAN         NOT NULL DEFAULT FALSE,  -- visible par tout le monde ou juste le créateur
    statut              VARCHAR(20)     NOT NULL DEFAULT 'a_faire'
                            CHECK (statut IN ('a_faire', 'traite')),
    created_by          BIGINT          REFERENCES employes(id) ON DELETE CASCADE,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_evenements_date    ON evenements (date_evenement);
CREATE INDEX IF NOT EXISTS idx_evenements_statut  ON evenements (statut);
CREATE INDEX IF NOT EXISTS idx_evenements_createur ON evenements (created_by);

-- Réutilise la fonction update_updated_at_column() déjà créée par le script principal
DROP TRIGGER IF EXISTS trg_evenements_updated_at ON evenements;
CREATE TRIGGER trg_evenements_updated_at
    BEFORE UPDATE ON evenements
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
