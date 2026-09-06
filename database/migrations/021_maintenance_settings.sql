USE hr1_database;

-- ============================================================
-- 021 — Maintenance Settings (Hybrid 4-state maintenance mode)
--
-- Single-row config table backing the maintenance engine in
-- includes/maintenance.php. Values are written from
-- Settings > System > Maintenance Mode (admin only) and audited
-- through security_log.
--   mode             : off | notice | limited | full
--   message          : optional admin message (escaped on output)
--   start_at/end_at  : optional schedule window (empty = always)
--   selected_modules : JSON array of slugs blocked in "limited"
--   updated_by       : users.id of the admin who changed it
-- ============================================================

CREATE TABLE IF NOT EXISTS maintenance_settings (
    id TINYINT UNSIGNED NOT NULL,
    mode VARCHAR(20) NOT NULL DEFAULT 'off',
    message TEXT NULL,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    selected_modules TEXT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_maintenance_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the single row. The legacy system_settings.sys_maintenance flag
-- (''0'' = off) is carried over as a one-time baseline: if it was ON, the
-- site was in full (block-all) mode, so default to ''full''.
SET @legacy_maintenance = (
    SELECT IFNULL((
        SELECT setting_value FROM system_settings WHERE setting_key = 'sys_maintenance' LIMIT 1
    ), '0')
);

INSERT INTO maintenance_settings (id, mode, message, start_at, end_at, selected_modules, updated_by, updated_at)
SELECT 1,
       IF(@legacy_maintenance = '1', 'full', 'off'),
       NULL, NULL, NULL, NULL, NULL, NOW()
WHERE NOT EXISTS (SELECT 1 FROM maintenance_settings WHERE id = 1);