-- =========================================================
-- MediPlus - Migration: Token Travel Estimate, Reminders,
-- Consultation Timer, Walk-in Tokens
-- Safe to run on an existing database. Pure additions only:
-- no existing column, table, or row is altered or removed.
-- Run once: mysql -u root mediplus < migration_token_travel.sql
-- =========================================================

USE mediplus;

-- ---------------------------------------------------------
-- APPOINTMENTS: track how a token was created, when the
-- doctor's timer actually started, and which reminders have
-- already fired (so patients aren't spammed).
-- ---------------------------------------------------------
ALTER TABLE appointments
    ADD COLUMN booking_type ENUM('online','walk_in') NOT NULL DEFAULT 'online' AFTER token,
    ADD COLUMN consultation_started_at DATETIME DEFAULT NULL AFTER completed_at,
    ADD COLUMN travel_reminder_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER consultation_started_at,
    ADD COLUMN added_by_admin_id INT DEFAULT NULL AFTER booking_type,
    ADD CONSTRAINT fk_appt_added_by_admin FOREIGN KEY (added_by_admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- PATIENTS: cached geocoded coordinates of the saved address,
-- so we don't re-hit the geocoding service on every page load.
-- ---------------------------------------------------------
ALTER TABLE patients
    ADD COLUMN latitude DECIMAL(10,7) DEFAULT NULL,
    ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL,
    ADD COLUMN geocoded_address VARCHAR(255) DEFAULT NULL;

-- ---------------------------------------------------------
-- TOKEN PROXIMITY REMINDERS: one row per (appointment, threshold)
-- so each "X tokens ahead" reminder is only ever sent once.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS token_reminders_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    threshold INT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_appt_threshold (appointment_id, threshold),
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- NEW SETTINGS: hospital fixed location + reminder/travel config.
-- Pre-filled with the hospital location provided by the admin
-- (Government District Head Quarters Hospital, Kangayam).
-- All of these are editable later from Admin > Hospital Settings.
-- ---------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
    ('hospital_name', 'Government District Head Quarters Hospital, Kangayam'),
    ('hospital_address', 'Government District Head Quarters Hospital, Kangayam, Tamil Nadu'),
    ('hospital_latitude', '11.0056419'),
    ('hospital_longitude', '77.5602020'),
    ('reminder_thresholds', '3,2,1,0'),
    ('checkin_buffer_minutes', '10'),
    ('avg_travel_speed_kmph', '30')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
