-- =====================================================================
-- Migration 013: exam_assignments.exam_id nullable (HR1 <-> HR3 readiness)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-09-03
--
-- PURPOSE
--   Allow HR1 to represent an EXAM REQUEST / ASSIGNMENT for an eligible
--   applicant linked to the Applied Job/Position even when no HR1-side
--   `exams` reference for the position exists yet. In the target
--   architecture the flow is:
--
--     Applicant -> Initial Screening PASSED -> Exam Assignment/Request
--                 -> Future HR3 -> Result -> HR1 validates -> PASSED
--                 -> Ready for Final Interview
--
--   HR1 does NOT own exam content (questions/answers/scoring live in HR3).
--   `exam_assignments.exam_id` was NOT NULL + FK -> exams(id), which forced
--   HR1 to pre-create an `exams` reference before it could record a request.
--   Making it nullable lets HR1 record a "requested / awaiting HR3"
--   assignment (exam_id = NULL) tied to the applicant + job only.
--
--   SEMANTICS
--     - exam_id = NULL      -> examination REQUESTED, awaiting HR3 /
--                              no exam reference defined for the position.
--     - exam_id = <id>      -> assigned a concrete exam reference (HR3).
--     - Result ingest still REQUIRES exam_id (needs the exam's passing
--       score); a null-exam request cannot be finalised until HR3 configures
--       the exam. This is enforced in code (includes/exam.php).
--
--   IDEMPOTENT: guarded via information_schema, additive-safe to re-run.
--
-- Rollback:
--   UPDATE exam_assignments SET exam_id = NULL WHERE exam_id IS NULL (n/a,
--   null-exam requests have no exam); then re-MODIFY exam_id NOT NULL:
--   ALTER TABLE exam_assignments MODIFY exam_id INT(11) NOT NULL;
-- =====================================================================

USE hr1_database;

-- 1. Drop the FK on exam_id (required before the column can be nullable).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_assignments'
      AND CONSTRAINT_NAME = 'exam_assignments_ibfk_1'
      AND REFERENCED_TABLE_NAME = 'exams'
);
SET @ddl1 := IF(@fk_exists > 0,
    'ALTER TABLE exam_assignments DROP FOREIGN KEY exam_assignments_ibfk_1',
    'SELECT "fk exam_assignments_ibfk_1 already gone" AS msg');
PREPARE stmt1 FROM @ddl1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. Make exam_id nullable (type stays int compatible with exams.id for the FK).
SET @col_null := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_assignments'
      AND COLUMN_NAME = 'exam_id' AND IS_NULLABLE = 'NO'
);
SET @ddl2 := IF(@col_null > 0,
    'ALTER TABLE exam_assignments MODIFY COLUMN exam_id INT(11) NULL',
    'SELECT "exam_id already nullable" AS msg');
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Re-create the FK (ON DELETE CASCADE, mirroring the pre-existing rule).
SET @fk_after := (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_assignments'
      AND CONSTRAINT_NAME = 'exam_assignments_ibfk_1'
      AND REFERENCED_TABLE_NAME = 'exams'
);
SET @ddl3 := IF(@fk_after = 0,
    'ALTER TABLE exam_assignments ADD CONSTRAINT exam_assignments_ibfk_1 FOREIGN KEY (exam_id) REFERENCES exams (id) ON DELETE CASCADE',
    'SELECT "fk exam_assignments_ibfk_1 present" AS msg');
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;