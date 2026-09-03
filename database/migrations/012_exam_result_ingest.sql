-- =====================================================================
-- Migration 012: HR1 exam-result ingestion (Part 4 — Exam Result -> Final Interview)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Created: 2026-09-03
--
-- PURPOSE
--   Add the HR1-side columns that let a secure external result (from the
--   future HR3 system) be associated with the correct attempt and defended
--   against replay / duplicate processing, WITHOUT storing any HR3 exam
--   content, questions, correct answers, or scoring engine.
--
--   exam_attempts.external_ref        - opaque HR1-issued attempt reference
--                                       (e.g. att_...) that HR1 shares with the
--                                       provider so the provider can reference a
--                                       specific sitting. Never a raw auto-inc id.
--   exam_attempts.source_provider     - which provider owns the attempt (HR3).
--   exam_results.external_result_id   - the provider's own immutable result id;
--                                       UNIQUE so re-submitting the same result
--                                       (replay) is rejected by HR1 server-side.
--   exam_results.received_via         - how the result arrived: 'api' (provider)
--                                       or 'manual' (HR web form).
--
--   The passing threshold itself is NOT stored on the result: HR1 recomputes
--   percentage from earned/total and derives pass/fail against the exam's
--   passing_score at ingest time. The external `passed` value is never trusted.
--
-- Idempotent: additive-only guards via information_schema.
-- =====================================================================

USE hr1_database;

-- ---- exam_attempts: opaque external attempt reference + source ----------
SET @col1 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_attempts'
                AND COLUMN_NAME = 'external_ref');
SET @ddl1 := IF(@col1 = 0,
    'ALTER TABLE exam_attempts ADD COLUMN external_ref VARCHAR(128) NULL DEFAULT NULL AFTER attempt_number, ADD KEY idx_at_external_ref (external_ref)',
    'SELECT "exam_attempts.external_ref already exists" AS msg');
PREPARE stmt1 FROM @ddl1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @col2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_attempts'
                AND COLUMN_NAME = 'source_provider');
SET @ddl2 := IF(@col2 = 0,
    'ALTER TABLE exam_attempts ADD COLUMN source_provider VARCHAR(80) NULL DEFAULT NULL AFTER external_ref',
    'SELECT "exam_attempts.source_provider already exists" AS msg');
PREPARE stmt2 FROM @ddl2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- ---- exam_results: provider result id (replay guard) + received_via ------
SET @col3 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_results'
                AND COLUMN_NAME = 'external_result_id');
SET @ddl3 := IF(@col3 = 0,
    'ALTER TABLE exam_results ADD COLUMN external_result_id VARCHAR(120) NULL DEFAULT NULL AFTER passed',
    'SELECT "exam_results.external_result_id already exists" AS msg');
PREPARE stmt3 FROM @ddl3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @idx3 := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_results'
                AND INDEX_NAME = 'uq_exam_result_external');
SET @ddl4 := IF(@idx3 = 0,
    'ALTER TABLE exam_results ADD UNIQUE KEY uq_exam_result_external (external_result_id)',
    'SELECT "exam_results.uq_exam_result_external already exists" AS msg');
PREPARE stmt4 FROM @ddl4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

SET @col4 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_results'
                AND COLUMN_NAME = 'received_via');
SET @ddl5 := IF(@col4 = 0,
    "ALTER TABLE exam_results ADD COLUMN received_via ENUM('api','manual') NOT NULL DEFAULT 'api' AFTER result_status",
    'SELECT "exam_results.received_via already exists" AS msg');
PREPARE stmt5 FROM @ddl5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;
