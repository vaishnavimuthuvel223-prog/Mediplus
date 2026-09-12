-- =========================================================
-- MediPlus - Migration: Patient Module (Consultation Records,
-- Prescriptions/Pharmacy, Lab Tests, Reports)
-- Safe to run on an existing database. Pure additions only:
-- no existing column, table, or row is altered or removed.
-- Run once: mysql -u root mediplus < migration_patient_module.sql
-- =========================================================

USE mediplus;

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
