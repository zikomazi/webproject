-- ============================================================
--  GESTION DE COURRIER DSG - Schéma v4
--  Base : suivi (PostgreSQL)
--  Workflow : 3 rôles (administrateur, secretariat, simple)
--  Nouveautés v4 : dates courrier/réception, ventilation avec
--  pilote/copie, réponse détaillée (table reponses), suivi de
--  clôture C9 (table c9).
-- ============================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

DROP VIEW  IF EXISTS v_courriers_retard CASCADE;
DROP TABLE IF EXISTS c9 CASCADE;
DROP TABLE IF EXISTS reponses CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS pieces_jointes CASCADE;
DROP TABLE IF EXISTS historique_actions CASCADE;
DROP TABLE IF EXISTS courrier_destinataires CASCADE;
DROP TABLE IF EXISTS courriers CASCADE;
DROP TABLE IF EXISTS employes CASCADE;
DROP TABLE IF EXISTS delais_priorite CASCADE;

-- ===================== employes =====================
CREATE TABLE employes (
    id                  BIGSERIAL PRIMARY KEY,
    nom_utilisateur     VARCHAR(50)     NOT NULL UNIQUE,
    password_hash       VARCHAR(255)    NOT NULL,
    nom                 VARCHAR(100)    NOT NULL,
    prenom              VARCHAR(100)    NOT NULL,
    email               VARCHAR(150),
    telephone           VARCHAR(20),
    role                VARCHAR(20)     NOT NULL DEFAULT 'simple'
                            CHECK (role IN ('administrateur', 'secretariat', 'simple')),
    actif               BOOLEAN         NOT NULL DEFAULT TRUE,
    derniere_connexion  TIMESTAMP,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== delais_priorite =====================
-- Délai accordé (en heures) selon la priorité, utilisé pour calculer
-- automatiquement courriers.date_limite à partir de date_envoi.
CREATE TABLE delais_priorite (
    priorite    VARCHAR(20) PRIMARY KEY
                    CHECK (priorite IN ('tres_urgent', 'urgent', 'simple')),
    duree_heures INTEGER NOT NULL CHECK (duree_heures > 0)
);

INSERT INTO delais_priorite (priorite, duree_heures) VALUES
    ('tres_urgent', 6),     -- 6 heures
    ('urgent',      12),    -- 12 heures
    ('simple',      120);   -- 5 jours = 120 heures

-- ===================== courriers =====================
-- Un courrier est créé et envoyé par le secrétariat (ou l'administrateur)
-- vers un ou plusieurs comptes "simple" (voir courrier_destinataires).
CREATE TABLE courriers (
    id                      BIGSERIAL PRIMARY KEY,
    reference               VARCHAR(50)     NOT NULL UNIQUE,   -- saisie libre par le secrétariat
    objet                   VARCHAR(500)    NOT NULL,
    priorite                VARCHAR(20)     NOT NULL DEFAULT 'simple'
                                CHECK (priorite IN ('tres_urgent', 'urgent', 'simple')),

    statut                  VARCHAR(20)     NOT NULL DEFAULT 'envoye'
                                CHECK (statut IN (
                                    'envoye',    -- vient d'être envoyé par le secrétariat
                                    'ouvert',    -- au moins un destinataire l'a consulté
                                    'en_cours',  -- un destinataire a démarré le traitement
                                    'repondu',   -- le secrétariat a enregistré la référence de réponse
                                    'archive'
                                )),

    date_courrier            DATE,                              -- date figurant sur le document
    date_reception           DATE,                              -- date d'arrivée physique au secrétariat
    date_envoi               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_limite              TIMESTAMP,                        -- calculée automatiquement

    observations              TEXT,

    created_by                BIGINT         REFERENCES employes(id) ON DELETE SET NULL,  -- secrétariat/admin ayant envoyé
    priorite_modifiee_par     BIGINT         REFERENCES employes(id) ON DELETE SET NULL,   -- admin ayant changé la priorité

    created_at                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== courrier_destinataires (VENTILATION) =====================
-- Liaison many-to-many : un courrier peut être ventilé vers plusieurs comptes
-- "simple". Si un seul destinataire, il est automatiquement "pilote".
-- Si plusieurs, un seul est désigné "pilote" (responsable), les autres
-- sont en "copie". On y trace aussi la consultation et le téléchargement
-- (pour les notifications) individuellement pour chaque destinataire.
CREATE TABLE courrier_destinataires (
    id                  BIGSERIAL PRIMARY KEY,
    courrier_id         BIGINT      NOT NULL REFERENCES courriers(id) ON DELETE CASCADE,
    employe_id          BIGINT      NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
    est_pilote          BOOLEAN     NOT NULL DEFAULT FALSE,
    date_ouverture       TIMESTAMP,          -- 1ère consultation par ce destinataire
    date_telechargement  TIMESTAMP,          -- 1er téléchargement par ce destinataire
    created_at            TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (courrier_id, employe_id)
);

-- Un seul pilote possible par courrier
CREATE UNIQUE INDEX idx_un_seul_pilote ON courrier_destinataires (courrier_id) WHERE est_pilote = TRUE;

-- ===================== pieces_jointes =====================
CREATE TABLE pieces_jointes (
    id              BIGSERIAL PRIMARY KEY,
    courrier_id     BIGINT          NOT NULL REFERENCES courriers(id) ON DELETE CASCADE,
    nom_fichier     VARCHAR(255)    NOT NULL,
    chemin          VARCHAR(500)    NOT NULL,
    type_mime       VARCHAR(100),
    taille          INTEGER,
    uploaded_by     BIGINT          REFERENCES employes(id) ON DELETE SET NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== reponses =====================
-- Réponse enregistrée par le secrétariat pour un courrier (référence,
-- date d'envoi, pièce jointe). Deux cases à cocher facultatives indiquent
-- si la réponse comporte un "projet de lettre" et/ou un "projet de
-- message" -- dans ce cas un suivi de clôture C9 est automatiquement créé.
CREATE TABLE reponses (
    id                  BIGSERIAL PRIMARY KEY,
    courrier_id         BIGINT          NOT NULL UNIQUE REFERENCES courriers(id) ON DELETE CASCADE,
    numero_reference     VARCHAR(50)     NOT NULL,
    date_envoi           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nom_fichier           VARCHAR(255),
    chemin_fichier        VARCHAR(500),
    projet_lettre          BOOLEAN       NOT NULL DEFAULT FALSE,
    projet_message         BOOLEAN       NOT NULL DEFAULT FALSE,
    created_by              BIGINT       REFERENCES employes(id) ON DELETE SET NULL,
    created_at               TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== c9 =====================
-- Suivi de clôture déclenché quand une réponse a "projet_lettre" et/ou
-- "projet_message" coché. Créé automatiquement en statut 'en_attente'
-- (badge rouge) ; renseigné ensuite via une popup dédiée, ce qui passe
-- son statut à 'cloture'.
CREATE TABLE c9 (
    id                  BIGSERIAL PRIMARY KEY,
    reponse_id          BIGINT          NOT NULL UNIQUE REFERENCES reponses(id) ON DELETE CASCADE,
    numero_reference     VARCHAR(50),
    date_envoi            TIMESTAMP,
    date_reception         TIMESTAMP,
    nom_fichier             VARCHAR(255),
    chemin_fichier           VARCHAR(500),
    statut                    VARCHAR(20) NOT NULL DEFAULT 'en_attente'
                                CHECK (statut IN ('en_attente', 'cloture')),
    created_by                 BIGINT     REFERENCES employes(id) ON DELETE SET NULL,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== historique_actions =====================
CREATE TABLE historique_actions (
    id              BIGSERIAL PRIMARY KEY,
    courrier_id     BIGINT          NOT NULL REFERENCES courriers(id) ON DELETE CASCADE,
    type_action     VARCHAR(50)     NOT NULL,  -- creation, changement_priorite, reassignation,
                                                -- changement_statut, reponse, commentaire
    ancien_valeur   TEXT,
    nouveau_valeur  TEXT,
    commentaire     TEXT,
    effectue_par    BIGINT          REFERENCES employes(id) ON DELETE SET NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== notifications =====================
-- Notifications destinées au secrétariat/admin quand un destinataire
-- ouvre ou télécharge un courrier.
CREATE TABLE notifications (
    id                  BIGSERIAL PRIMARY KEY,
    employe_id          BIGINT      NOT NULL REFERENCES employes(id) ON DELETE CASCADE, -- destinataire de la notif
    courrier_id         BIGINT      REFERENCES courriers(id) ON DELETE CASCADE,
    declenche_par       BIGINT      REFERENCES employes(id) ON DELETE SET NULL,          -- le compte simple concerné
    type                VARCHAR(30) NOT NULL,   -- ouverture, telechargement
    message             TEXT        NOT NULL,
    lu                  BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ===================== INDEX =====================
CREATE INDEX idx_employes_nom_utilisateur   ON employes (nom_utilisateur);
CREATE INDEX idx_employes_role              ON employes (role);

CREATE INDEX idx_courriers_reference        ON courriers (reference);
CREATE INDEX idx_courriers_statut           ON courriers (statut);
CREATE INDEX idx_courriers_priorite         ON courriers (priorite);
CREATE INDEX idx_courriers_date_limite      ON courriers (date_limite);
CREATE INDEX idx_courriers_created_at       ON courriers (created_at DESC);

CREATE INDEX idx_destinataires_courrier     ON courrier_destinataires (courrier_id);
CREATE INDEX idx_destinataires_employe      ON courrier_destinataires (employe_id);

CREATE INDEX idx_historique_courrier        ON historique_actions (courrier_id);
CREATE INDEX idx_pj_courrier                ON pieces_jointes (courrier_id);
CREATE INDEX idx_notifications_employe      ON notifications (employe_id, lu);
CREATE INDEX idx_reponses_courrier          ON reponses (courrier_id);
CREATE INDEX idx_c9_reponse                 ON c9 (reponse_id);
CREATE INDEX idx_c9_statut                  ON c9 (statut);

-- ===================== TRIGGER updated_at =====================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_employes_updated_at
    BEFORE UPDATE ON employes
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_courriers_updated_at
    BEFORE UPDATE ON courriers
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER trg_c9_updated_at
    BEFORE UPDATE ON c9
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ===================== TRIGGER calcul auto date_limite =====================
CREATE OR REPLACE FUNCTION calc_date_limite()
RETURNS TRIGGER AS $$
DECLARE
    v_duree_heures INTEGER;
BEGIN
    SELECT duree_heures INTO v_duree_heures
    FROM delais_priorite
    WHERE priorite = COALESCE(NEW.priorite, 'simple');

    IF v_duree_heures IS NOT NULL THEN
        NEW.date_limite := COALESCE(NEW.date_envoi, CURRENT_TIMESTAMP) + (v_duree_heures || ' hours')::INTERVAL;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_courriers_calc_date_limite
    BEFORE INSERT OR UPDATE OF date_envoi, priorite ON courriers
    FOR EACH ROW EXECUTE FUNCTION calc_date_limite();

-- ===================== VUE courriers en retard =====================
-- Calculée à la volée (jamais stockée) : un courrier est en retard si sa
-- date_limite est dépassée et qu'il n'a pas encore de réponse enregistrée.
CREATE VIEW v_courriers_retard AS
SELECT *
FROM courriers
WHERE date_limite IS NOT NULL
  AND date_limite < CURRENT_TIMESTAMP
  AND statut NOT IN ('repondu', 'archive');

-- ===================== COMPTE ADMIN =====================
-- Login : admin   |   Mot de passe : admin123
INSERT INTO employes (nom_utilisateur, password_hash, nom, prenom, email, role)
VALUES (
    'admin',
    '$2y$10$Z7WbRiiZbSq9L.UcV8BS2uveScAMFy3x4eubFkx0sPtVmr/rG6D/O',
    'Admin',
    'Système',
    'admin@dsg.local',
    'administrateur'
);
