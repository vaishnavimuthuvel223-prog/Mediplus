-- =========================================================
-- MediPlus - Centralized Smart Hospital Management System
-- Phase 1 Database Schema
-- =========================================================

CREATE DATABASE IF NOT EXISTS mediplus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mediplus;

-- ---------------------------------------------------------
-- USERS (base auth table for all roles)
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('patient','doctor','nurse','admin','management') NOT NULL DEFAULT 'patient',
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DEPARTMENTS
-- ---------------------------------------------------------
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- SPECIALIZATIONS
-- ---------------------------------------------------------
CREATE TABLE specializations (
    specialization_id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    name VARCHAR(100) NOT NULL UNIQUE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- PATIENTS
-- ---------------------------------------------------------
CREATE TABLE patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    age_category ENUM('child','adult','senior') NOT NULL DEFAULT 'adult',
    gender ENUM('male','female','other') NOT NULL DEFAULT 'other',
    phone VARCHAR(20) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    geocoded_address VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DOCTORS
-- ---------------------------------------------------------
CREATE TABLE doctors (
    doctor_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    specialization_id INT NOT NULL,
    status ENUM('available','busy','unavailable') NOT NULL DEFAULT 'available',
    avg_consultation_minutes INT NOT NULL DEFAULT 15,
    face_template LONGTEXT DEFAULT NULL,
    face_enrolled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES specializations(specialization_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- DOCTOR SCHEDULES / AVAILABILITY (future-ready)
-- ---------------------------------------------------------
CREATE TABLE doctor_schedules (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    day_of_week TINYINT NOT NULL, -- 0=Sunday .. 6=Saturday
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- NURSES (future support - structure only)
-- ---------------------------------------------------------
CREATE TABLE nurses (
    nurse_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    department_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- ADMIN (future support - structure only)
-- ---------------------------------------------------------
CREATE TABLE admins (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- HOSPITAL MANAGEMENT (future support - structure only)
-- ---------------------------------------------------------
CREATE TABLE hospital_management (
    management_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- APPOINTMENTS
-- ---------------------------------------------------------
CREATE TABLE appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT DEFAULT NULL,
    specialization_id INT NOT NULL,
    token VARCHAR(10) NOT NULL UNIQUE,
    booking_type ENUM('online','walk_in') NOT NULL DEFAULT 'online',
    added_by_admin_id INT DEFAULT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    booking_emergency_level ENUM('normal','urgent','critical') NOT NULL DEFAULT 'normal',
    arrival_status ENUM('not_arrived','arrived','late') NOT NULL DEFAULT 'not_arrived',
    arrival_time DATETIME DEFAULT NULL,
    status ENUM('scheduled','arrived','waiting','in_consultation','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    preparation_status ENUM('pending','in_progress','ready') NOT NULL DEFAULT 'pending',
    nurse_notes VARCHAR(255) DEFAULT NULL,
    previous_status_flag ENUM('none','no_show','completed_before') NOT NULL DEFAULT 'none',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    consultation_started_at DATETIME DEFAULT NULL,
    travel_reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id) ON DELETE SET NULL,
    FOREIGN KEY (specialization_id) REFERENCES specializations(specialization_id),
    FOREIGN KEY (added_by_admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_appt_status ON appointments(status);
CREATE INDEX idx_appt_date ON appointments(appointment_date);

-- ---------------------------------------------------------
-- NURSE VITALS
-- ---------------------------------------------------------
CREATE TABLE nurse_vitals (
    vital_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    nurse_id INT NOT NULL,
    temperature DECIMAL(4,1) DEFAULT NULL,
    blood_pressure VARCHAR(20) DEFAULT NULL,
    pulse INT DEFAULT NULL,
    oxygen_saturation INT DEFAULT NULL,
    weight_kg DECIMAL(5,1) DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (nurse_id) REFERENCES nurses(nurse_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_nurse_vitals_appointment ON nurse_vitals(appointment_id);
CREATE INDEX idx_nurse_vitals_nurse ON nurse_vitals(nurse_id);

-- ---------------------------------------------------------
-- TOKEN PROXIMITY REMINDERS (one row per appointment/threshold
-- so a patient is never notified twice for the same milestone)
-- ---------------------------------------------------------
CREATE TABLE token_reminders_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    threshold INT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_appt_threshold (appointment_id, threshold),
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- QUEUE ENTRIES
-- ---------------------------------------------------------
CREATE TABLE queue_entries (
    queue_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    priority_score DECIMAL(10,2) NOT NULL DEFAULT 0,
    queue_position INT DEFAULT NULL,
    queue_status ENUM('waiting','in_consultation','completed','cancelled') NOT NULL DEFAULT 'waiting',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_queue_status ON queue_entries(queue_status);

-- ---------------------------------------------------------
-- CONSULTATIONS: doctor's notes/diagnosis/instructions for a
-- completed (or in-progress) token. One row per appointment.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS consultations (
    consultation_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    doctor_id INT NOT NULL,
    patient_id INT NOT NULL,
    patient_type ENUM('new','returning') NOT NULL DEFAULT 'new',
    diagnosis VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    instructions TEXT DEFAULT NULL,
    follow_up VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id),
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- PRESCRIPTIONS: pharmacy status for a consultation's medicine
-- order. One row per appointment (a consultation may or may not
-- have a prescription).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS prescriptions (
    prescription_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    doctor_id INT NOT NULL,
    patient_id INT NOT NULL,
    status ENUM('prescribed','sent_to_pharmacy','processing','ready','dispensed') NOT NULL DEFAULT 'prescribed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id),
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
) ENGINE=InnoDB;

CREATE INDEX idx_prescription_status ON prescriptions(status);

-- ---------------------------------------------------------
-- PRESCRIPTION ITEMS: individual medicines within a prescription.
-- Doctor-entered only - never auto-generated.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS prescription_items (
    item_id INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT NOT NULL,
    medicine VARCHAR(150) NOT NULL,
    dosage VARCHAR(80) DEFAULT NULL,
    frequency VARCHAR(80) DEFAULT NULL,
    duration VARCHAR(80) DEFAULT NULL,
    instructions VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (prescription_id) REFERENCES prescriptions(prescription_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- LAB TESTS: tests requested by the doctor during a consultation.
-- Doctor-entered test names only - results are never auto-generated,
-- only recorded once actually available.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS lab_tests (
    lab_test_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    doctor_id INT NOT NULL,
    patient_id INT NOT NULL,
    test_name VARCHAR(150) NOT NULL,
    status ENUM('requested','processing','result_available') NOT NULL DEFAULT 'requested',
    result_text TEXT DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id),
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id)
) ENGINE=InnoDB;

CREATE INDEX idx_lab_status ON lab_tests(status);
CREATE INDEX idx_lab_appointment ON lab_tests(appointment_id);

-- ---------------------------------------------------------
-- EMERGENCY REQUESTS
-- ---------------------------------------------------------
CREATE TABLE emergency_requests (
    emergency_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_id INT DEFAULT NULL,
    specialization_id INT DEFAULT NULL,
    status ENUM('triggered','priority_assigned','staff_alerted','acknowledged','awaiting_doctor','doctor_assigned','response_in_progress','in_progress','handled') NOT NULL DEFAULT 'triggered',
    triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME DEFAULT NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE SET NULL,
    FOREIGN KEY (specialization_id) REFERENCES specializations(specialization_id)
) ENGINE=InnoDB;

CREATE INDEX idx_emergency_status ON emergency_requests(status);

-- ---------------------------------------------------------
-- EMERGENCY LOCATIONS
-- ---------------------------------------------------------
CREATE TABLE emergency_locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    emergency_id INT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (emergency_id) REFERENCES emergency_requests(emergency_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- SETTINGS (configurable values e.g. avg consultation duration)
-- ---------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_value) VALUES
('avg_consultation_minutes', '15'),
('wait_weight_per_minute', '2'),
('emergency_score', '1000'),
('urgent_booking_score', '150'),
('critical_booking_score', '300'),
('senior_age_bonus', '20'),
('child_age_bonus', '15'),
('hospital_name', 'Government District Head Quarters Hospital, Kangayam'),
('hospital_address', 'Government District Head Quarters Hospital, Kangayam, Tamil Nadu'),
('hospital_latitude', '11.0056419'),
('hospital_longitude', '77.5602020'),
('reminder_thresholds', '3,2,1,0'),
('checkin_buffer_minutes', '10'),
('avg_travel_speed_kmph', '30');

-- ---------------------------------------------------------
-- SEED DATA
-- ---------------------------------------------------------
INSERT INTO departments (name) VALUES ('General Medicine'),('Cardiology'),('Orthopedics'),('Pediatrics'),('Emergency Medicine');

INSERT INTO specializations (department_id, name) VALUES
(1,'General Physician'),
(2,'Cardiologist'),
(3,'Orthopedic Surgeon'),
(4,'Pediatrician'),
(5,'Emergency Medicine Specialist');

-- Issued staff accounts. Passwords are stored as secure hashes; never expose them in the UI.
INSERT INTO users (role, username, email, password_hash) VALUES
('admin', 'admin_001', 'admin.001@mediplus.local', '$2y$10$kdg0xft0MAz3OqaP.auNSOUogVZg9kNpl/Q8xR7FxwI4ekC3uCPyi');

INSERT INTO admins (user_id, full_name)
SELECT user_id, 'MediPlus Administrator' FROM users WHERE username = 'admin_001';

-- Three issued doctor accounts. Password for all seeded doctors: Doctor@2026!
INSERT INTO users (role, username, email, password_hash) VALUES
('doctor', 'doc_dept1_01', 'doc.dept1.01@mediplus.local', '$2y$10$A.KHwuIKHlf2OY/qTKFdiOLdTU./KDYHR8L72bFzlw/EbjjkmKzrG'),
('doctor', 'doc_dept2_01', 'doc.dept2.01@mediplus.local', '$2y$10$IjD9GyG63y9HqDrgilfe1uhXPfUgHOs5OJkYN8PhcUK/2n5KaKKEu'),
('doctor', 'doc_dept3_01', 'doc.dept3.01@mediplus.local', '$2y$10$i6Olalii2.sPReQw2UZpMuUnMmK2V2.OfcZz3y/1fcQlbyAI1WAPS');

INSERT INTO doctors (user_id, full_name, specialization_id, status)
SELECT user_id, 'Vaishnavi Muthuvel', 1, 'available' FROM users WHERE username = 'doc_dept1_01';
INSERT INTO doctors (user_id, full_name, specialization_id, status)
SELECT user_id, 'Varshini VC', 2, 'available' FROM users WHERE username = 'doc_dept2_01';
INSERT INTO doctors (user_id, full_name, specialization_id, status)
SELECT user_id, 'Shanthiyashri', 3, 'available' FROM users WHERE username = 'doc_dept3_01';
