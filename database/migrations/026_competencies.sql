-- =====================================================================
-- Migration 026: Competency catalog — ESS "My Competencies" backend (Phase 3A)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Target:  MariaDB 10.4.32
--
-- WHAT THIS CHANGES (and nothing else):
--   NEW TABLE competencies — the single reusable competency catalog.
--   No existing table, employee record, department, or auth data is touched.
--
-- HOW IT CONNECTS TO EXISTING HR1 TABLES:
--   competencies.created_by → users(id)   (FK, ON DELETE SET NULL)
--
-- WHY A SINGLE CATALOG (no duplication):
--   HR1's My Performance module has NO competency system by design. A future
--   phase will add review_competency_results that references THIS catalog
--   (competencies → employee_competencies → review_competency_results →
--   performance_reviews). Competency definitions are therefore never copied
--   into performance_reviews.
--
-- RATING SCALE:
--   Levels are NOT stored on the catalog. The 1-5 scale already lives in
--   rating_options (scale_code = 'competency', seeded in migration 025) and
--   is reused as-is. The catalog defines WHAT a competency is; rating_options
--   defines HOW it is rated; employee_competencies (migration 027) stores the
--   actual required + current level per employee.
--
-- DEFINITIONS:
--   is_active = 1  catalog entry visible/assignable
--   is_active = 0  soft-deleted (archived); existing assignments preserved
--
-- Rollback:
--   DROP TABLE IF EXISTS competencies;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS competencies (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL COMMENT 'Competency name, e.g. Communication Skills',
    description TEXT NULL COMMENT 'What this competency covers (optional)',
    category    VARCHAR(60) NULL COMMENT 'Grouping, e.g. Technical, Behavioral, Leadership',
    is_active   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = assignable; 0 = archived',
    created_by  INT NULL COMMENT 'FK → users(id); HR who created it',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_competencies_name (name),
    KEY idx_competencies_active (is_active),
    KEY idx_competencies_category (category),
    CONSTRAINT fk_competencies_creator
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;