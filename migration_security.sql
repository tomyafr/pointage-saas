-- ============================================
-- MIGRATION DE SÉCURITÉ — Pointage Atelier SaaS
-- Version: 2.2.0-security
-- Date: 2026-02-22
-- ============================================
-- Exécuter ce script sur une base de données EXISTANTE pour appliquer
-- les correctifs de sécurité sans perdre les données.
-- ============================================

-- 1. Ajouter la colonne must_change_password aux utilisateurs existants
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;

-- 2. Forcer le changement de mot de passe pour tous les utilisateurs
--    (car les mots de passe par défaut sont compromis)
UPDATE users SET must_change_password = TRUE;

-- 3. Ajouter la contrainte CHECK sur les heures (si absente)
-- Note: PostgreSQL ne permet pas d'ajouter simplement une contrainte CHECK sur une colonne existante
-- sans recréer la table, mais on peut l'ajouter comme contrainte de table :
DO $$
BEGIN
    BEGIN
        ALTER TABLE pointages ADD CONSTRAINT chk_heures_range CHECK (heures > 0 AND heures <= 24);
    EXCEPTION WHEN duplicate_object THEN
        RAISE NOTICE 'Contrainte chk_heures_range déjà présente, ignorée.';
    END;
END;
$$;

-- 4. Ajouter les index manquants sur audit_logs
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);

-- 5. Optionnel : Vider les anciennes tentatives de connexion
-- TRUNCATE login_attempts;

-- ============================================
-- VÉRIFICATION FINALE
-- ============================================
SELECT
    'users'         AS table_name,
    COUNT(*)        AS count,
    COUNT(*) FILTER (WHERE must_change_password = TRUE) AS must_change
FROM users
UNION ALL
SELECT 'login_attempts', COUNT(*), NULL FROM login_attempts
UNION ALL
SELECT 'audit_logs', COUNT(*), NULL FROM audit_logs;
