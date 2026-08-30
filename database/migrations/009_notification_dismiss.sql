-- Migration 009 — Notification dismissal (hide-from-bell, keep history)
-- Adds a non-destructive "dismissed" flag to notifications so a notification
-- can be removed from the active bell while remaining in the permanent
-- "View All Notifications" history.
-- Safe to re-run.

USE hr1_database;

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS dismissed_at DATETIME NULL DEFAULT NULL AFTER is_read;

CREATE INDEX IF NOT EXISTS idx_notifications_active ON notifications (user_id, is_read, dismissed_at);
