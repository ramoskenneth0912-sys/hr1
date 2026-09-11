-- =====================================================================
-- Migration 025: Performance review structure — "My Performance" (Phase 2)
-- System:  TRI-M GLOBAL — Merchandising Management System (HR1)
-- Target:  MariaDB 10.4.32
--
-- WHAT THIS CHANGES (and nothing else):
--   NEW TABLES (5) for the HR Performance management module:
--     1. review_periods           HR-defined evaluation windows
--     2. performance_reviews      one review per employee per period
--     3. review_goal_results      link + scoring to existing employee_goals
--     4. rating_options           configurable 1-5 rating scale (seed data)
--     5. performance_review_log   status-transition audit trail
--   No existing table or employee record is modified.
--
-- REUSE / INTEGRATION (no duplication):
--   - Employees, departments, users, employee_goals are reused as-is.
--   - Goals are NOT copied into a review. review_goal_results only links
--     a goal and stores the POINT-IN-TIME rating + snapshot metadata,
--     so a finalized review reflects the state the goal had at review time.
--   - No competency tables: the competency catalog does not exist yet.
--     (A future phase will add review_competency_scores referencing a
--     single competencies catalog owned by the My Competencies module.)
--
-- LIFECYCLE (stored): drafted → assigned → self_assessment →
--   manager_review → finalized → acknowledged.
--   Transitions are enforced server-side by the API, never by column.
--
-- RATING SCALE (rating_options seed, approved):
--   1 Needs Improvement · 2 Developing · 3 Meets Expectations
--   4 Exceeds Expectations · 5 Outstanding
--   Applied to goal, competency (future) and overall ratings.
--
-- Rollback:
--   DROP TABLE IF EXISTS performance_review_log, review_goal_results,
--                      performance_reviews, review_periods, rating_options;
-- =====================================================================

USE hr1_database;

CREATE TABLE IF NOT EXISTS review_periods (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL COMMENT 'Readable e.g. Annual Review 2026',
    period_type ENUM('annual','semi_annual','quarterly','probationary') NOT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('planned','open','closed') NOT NULL DEFAULT 'planned',
    created_by  INT NULL COMMENT 'FK → users(id); HR who defined the period',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_periods_name (name),
    KEY idx_review_periods_status (status),
    CONSTRAINT fk_review_periods_creator
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_review_periods_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS performance_reviews (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id           INT NOT NULL COMMENT 'FK → employees(id); subject of review',
    period_id             INT UNSIGNED NOT NULL COMMENT 'FK → review_periods(id)',
    reviewer_user_id      INT NULL COMMENT 'FK → users(id); practical reviewer until manager_id is populated',
    status                ENUM('drafted','assigned','self_assessment','manager_review','finalized','acknowledged')
                          NOT NULL DEFAULT 'drafted',
    self_assessment       LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                          COMMENT 'JSON {strengths, improvements, comments}; draft until submit',
    self_submitted_at     DATETIME NULL,
    manager_feedback      TEXT NULL,
    manager_rating        TINYINT UNSIGNED NULL COMMENT '1-5, manager-only',
    weight_config         LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL
                          COMMENT 'JSON weight snapshot e.g. {"goal":50,"manager":50} (fallback to default)',
    final_rating          TINYINT UNSIGNED NULL COMMENT '1-5, computed at finalize',
    final_rating_label    VARCHAR(60) NULL COMMENT 'Label snapshot from rating_options at finalize',
    acknowledged_at       DATETIME NULL,
    acknowledge_note      TEXT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reviews_employee_period (employee_id, period_id),
    KEY idx_reviews_status (status),
    KEY idx_reviews_period (period_id),
    KEY idx_reviews_reviewer (reviewer_user_id),
    CONSTRAINT fk_reviews_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_period
        FOREIGN KEY (period_id) REFERENCES review_periods (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reviewer
        FOREIGN KEY (reviewer_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_reviews_manager_rating CHECK (manager_rating IS NULL OR manager_rating BETWEEN 1 AND 5),
    CONSTRAINT chk_reviews_final_rating CHECK (final_rating IS NULL OR final_rating BETWEEN 1 AND 5),
    CONSTRAINT chk_reviews_self_json CHECK (self_assessment IS NULL OR json_valid(self_assessment)),
    CONSTRAINT chk_reviews_weight_json CHECK (weight_config IS NULL OR json_valid(weight_config))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS review_goal_results (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id             INT UNSIGNED NOT NULL COMMENT 'FK → performance_reviews(id)',
    goal_id               INT UNSIGNED NOT NULL COMMENT 'FK → employee_goals(id); live goal record',
    rating                TINYINT UNSIGNED NULL COMMENT '1-5, set by reviewer during grading',
    result_notes          TEXT NULL COMMENT 'Reviewer notes on this goal result',
    goal_title            VARCHAR(160) NOT NULL COMMENT 'Snapshot at link/scoring time',
    goal_status_snapshot  VARCHAR(20) NOT NULL COMMENT 'Snapshot e.g. completed / in_progress',
    goal_progress_snapshot TINYINT UNSIGNED NOT NULL COMMENT 'Snapshot 0-100',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_goal_result_review_goal (review_id, goal_id),
    KEY idx_goal_result_goal (goal_id),
    CONSTRAINT fk_goal_result_review
        FOREIGN KEY (review_id) REFERENCES performance_reviews (id) ON DELETE CASCADE,
    CONSTRAINT fk_goal_result_goal
        FOREIGN KEY (goal_id) REFERENCES employee_goals (id) ON DELETE CASCADE,
    CONSTRAINT chk_goal_result_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
    CONSTRAINT chk_goal_result_progress CHECK (goal_progress_snapshot BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rating_options (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scale_code  VARCHAR(20) NOT NULL COMMENT 'goal | competency | overall',
    value       TINYINT UNSIGNED NOT NULL COMMENT '1-5',
    label       VARCHAR(60) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rating_options_scale_value (scale_code, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approved 1-5 rating scale (config; safe to re-run).
INSERT INTO rating_options (scale_code, value, label, sort_order) VALUES
    ('goal',      1, 'Needs Improvement',    1),
    ('goal',      2, 'Developing',           2),
    ('goal',      3, 'Meets Expectations',   3),
    ('goal',      4, 'Exceeds Expectations', 4),
    ('goal',      5, 'Outstanding',          5),
    ('competency',1, 'Needs Improvement',    1),
    ('competency',2, 'Developing',           2),
    ('competency',3, 'Meets Expectations',   3),
    ('competency',4, 'Exceeds Expectations', 4),
    ('competency',5, 'Outstanding',          5),
    ('overall',   1, 'Needs Improvement',    1),
    ('overall',   2, 'Developing',           2),
    ('overall',   3, 'Meets Expectations',   3),
    ('overall',   4, 'Exceeds Expectations', 4),
    ('overall',   5, 'Outstanding',          5)
ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS performance_review_log (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id     INT UNSIGNED NOT NULL COMMENT 'FK → performance_reviews(id)',
    from_status   VARCHAR(24) NULL,
    to_status     VARCHAR(24) NOT NULL,
    action        VARCHAR(60) NOT NULL,
    actor_user_id INT NULL COMMENT 'FK → users(id)',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_review_log_review (review_id),
    CONSTRAINT fk_review_log_review
        FOREIGN KEY (review_id) REFERENCES performance_reviews (id) ON DELETE CASCADE,
    CONSTRAINT fk_review_log_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;