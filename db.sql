-- ============================================
-- POINTAGE SAAS - Schema de base de données (VERSION POSTGRESQL / VERCEL)
-- ============================================

-- Nettoyage pour repartir à zéro
DROP TABLE IF EXISTS audit_logs CASCADE;
DROP TABLE IF EXISTS login_attempts CASCADE;
DROP TABLE IF EXISTS sync_log CASCADE;
DROP TABLE IF EXISTS pointages CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ============================================
-- TABLE DES UTILISATEURS
-- ============================================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL UNIQUE,
    prenom VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'operateur' CHECK (role IN ('operateur', 'chef')),
    actif BOOLEAN NOT NULL DEFAULT TRUE,
    -- Forcer le changement de mot de passe à la première connexion
    must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE DES POINTAGES
-- ============================================
CREATE TABLE IF NOT EXISTS pointages (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id),
    numero_of VARCHAR(50) NOT NULL,
    heures DECIMAL(5,2) NOT NULL CHECK (heures > 0 AND heures <= 24),
    date_pointage DATE NOT NULL,
    synced_bc BOOLEAN NOT NULL DEFAULT FALSE,
    synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, date_pointage, numero_of)
);

-- ============================================
-- TABLE DE LOG DES SYNCHRONISATIONS BC
-- ============================================
CREATE TABLE IF NOT EXISTS sync_log (
    id SERIAL PRIMARY KEY,
    chef_id INT NOT NULL REFERENCES users(id),
    nb_pointages INT NOT NULL,
    status VARCHAR(20) NOT NULL CHECK (status IN ('success', 'error')),
    response_data TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE D'AUDIT (Actions sensibles)
-- ============================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id),
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE ANTI-BRUTE FORCE (Rate Limiting)
-- ============================================
CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) PRIMARY KEY,
    attempts INT DEFAULT 1,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- INDEX POUR LES REQUÊTES FRÉQUENTES
-- ============================================
CREATE INDEX idx_pointages_date ON pointages(date_pointage);
CREATE INDEX idx_pointages_of ON pointages(numero_of);
CREATE INDEX idx_pointages_synced ON pointages(synced_bc);
CREATE INDEX idx_audit_user ON audit_logs(user_id);
CREATE INDEX idx_audit_action ON audit_logs(action);
CREATE INDEX idx_audit_created ON audit_logs(created_at);

-- ============================================
-- DONNÉES INITIALES
-- ============================================
-- Mot de passe temporaire : password123
-- Hash bcrypt (coût 10) valide de "password123"
--
-- ⚠️  IMPORTANT : Ce mot de passe est INTENTIONNELLEMENT faible mais
--     must_change_password = TRUE force TOUS les utilisateurs à le changer
--     dès leur première connexion — ils ne pourront pas accéder à l'app
--     sans définir un nouveau mot de passe fort (12+ car, complexité requise).
--
-- Pour générer votre propre hash (ex: sur votre serveur PHP) :
--   php -r "echo password_hash('VotreMotDePasse', PASSWORD_BCRYPT, ['cost' => 12]);"

INSERT INTO users (nom, prenom, password_hash, role, must_change_password) VALUES
('CHRIST',      'Olivier',   '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'chef',      TRUE),
('LOTITO',      'Pierre',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE),
('BUDIN',       'Aymeric',   '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE),
('MANGIN',      'Maxime',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE),
('LAFOND',      'Vivian',    '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE),
('CHRISTIANY',  'Jean-Paul', '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE),
('TONETTO',     'Jean-Marc', '$2y$10$KJ7isoaShujh8NKqoYOE/OUHe.Z63dzC068Lu3jJYYxPXK8ngiQjG', 'operateur', TRUE)
ON CONFLICT (nom) DO UPDATE
    SET password_hash        = EXCLUDED.password_hash,
        must_change_password = EXCLUDED.must_change_password;

-- ============================================
-- MIGRATION : Si la table users existe déjà (sans must_change_password)
-- Décommentez et exécutez uniquement ces 2 lignes :
-- ============================================
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT TRUE;
-- UPDATE users SET must_change_password = TRUE;


-- ============================================
-- TABLE TRACKING EN DIRECT
-- ============================================
CREATE TABLE IF NOT EXISTS active_sessions (
    user_id INT PRIMARY KEY REFERENCES users(id),
    numero_of VARCHAR(50) NOT NULL,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
