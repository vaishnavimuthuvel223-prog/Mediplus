<?php
// =========================================================
// MediPlus - Patient Module: Consultation Records, Pharmacy
// (Prescriptions) and Lab Test flow.
//
// Built entirely on top of the existing appointment/queue engine
// (includes/appointment_functions.php + includes/priority_engine.php).
// Does NOT create a second queue or a second priority algorithm -
// these functions only run at/after the moment an existing token is
// completed, and read from the existing appointments/patients tables.
// =========================================================

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/appointment_functions.php';

/**
 * Whether a patient is NEW or RETURNING, based purely on whether they
 * have any other completed appointment before this one. Computed on
 * the fly from the existing appointments table - no extra column.
 */
function get_patient_type($pdo, $patient_id, $exclude_appointment_id = null) {
    $sql = "
        SELECT COUNT(*) AS cnt FROM appointments
        WHERE patient_id = ? AND status = 'completed'
    ";
    $params = [$patient_id];
    if ($exclude_appointment_id) {
        $sql .= " AND appointment_id != ?";
        $params[] = $exclude_appointment_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = (int) $stmt->fetch()['cnt'];
    return $count > 0 ? 'returning' : 'new';
}

/**
 * Saves the doctor's consultation record (diagnosis/notes/instructions/
 * follow-up), any prescription items, and any lab test requests, then
 * completes the token via the existing complete_consultation() workflow
 * (doctor becomes available, next eligible token moves forward
 * automatically, emergency/queue state all updated exactly as before).
 *
 * This is the one place the "doctor writes notes -> adds prescription
 * -> adds instructions -> submits/completes" flow (spec section 3) is
 * implemented. Nothing here touches priority scoring or queue order.
 *
 * @param array $prescriptionItems  each: ['medicine'=>, 'dosage'=>, 'frequency'=>, 'duration'=>, 'instructions'=>]
 * @param array $labTests           each: test name string
 * @return array ['success' => bool, 'message' => string]
 */
function save_consultation_and_complete($pdo, $doctor_id, $appointment_id, $diagnosis, $notes, $instructions, $followUp, array $prescriptionItems, array $labTests) {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND doctor_id = ?");
    $stmt->execute([$appointment_id, $doctor_id]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not assigned to this doctor.'];
    if ($appt['status'] !== 'in_consultation') {
        return ['success' => false, 'message' => 'Patient is not currently in consultation.'];
    }

    $patient_id = (int) $appt['patient_id'];

    // Clean prescription items / lab test names - blank rows are dropped,
    // nothing is auto-filled or auto-generated.
    $prescriptionItems = array_values(array_filter(array_map(function ($row) {
        $medicine = trim((string) ($row['medicine'] ?? ''));
        if ($medicine === '') return null;
        return [
            'medicine' => $medicine,
            'dosage' => trim((string) ($row['dosage'] ?? '')) ?: null,
            'frequency' => trim((string) ($row['frequency'] ?? '')) ?: null,
            'duration' => trim((string) ($row['duration'] ?? '')) ?: null,
            'instructions' => trim((string) ($row['instructions'] ?? '')) ?: null,
        ];
    }, $prescriptionItems)));

    $labTests = array_values(array_filter(array_map(function ($name) {
        $name = trim((string) $name);
        return $name === '' ? null : $name;
    }, $labTests)));

    $pdo->beginTransaction();
    try {
        $patientType = get_patient_type($pdo, $patient_id, $appointment_id);

        $consultStmt = $pdo->prepare("
            INSERT INTO consultations (appointment_id, doctor_id, patient_id, patient_type, diagnosis, notes, instructions, follow_up)
            VALUES (:appointment_id, :doctor_id, :patient_id, :patient_type, :diagnosis, :notes, :instructions, :follow_up)
            ON DUPLICATE KEY UPDATE
                diagnosis = VALUES(diagnosis), notes = VALUES(notes),
                instructions = VALUES(instructions), follow_up = VALUES(follow_up)
        ");
        $consultStmt->execute([
            ':appointment_id' => $appointment_id,
            ':doctor_id' => $doctor_id,
            ':patient_id' => $patient_id,
            ':patient_type' => $patientType,
            ':diagnosis' => trim((string) $diagnosis) ?: null,
            ':notes' => trim((string) $notes) ?: null,
            ':instructions' => trim((string) $instructions) ?: null,
            ':follow_up' => trim((string) $followUp) ?: null,
        ]);

        if (!empty($prescriptionItems)) {
            $prescStmt = $pdo->prepare("
                INSERT INTO prescriptions (appointment_id, doctor_id, patient_id, status)
                VALUES (:appointment_id, :doctor_id, :patient_id, 'sent_to_pharmacy')
                ON DUPLICATE KEY UPDATE doctor_id = VALUES(doctor_id)
            ");
            $prescStmt->execute([
                ':appointment_id' => $appointment_id,
                ':doctor_id' => $doctor_id,
                ':patient_id' => $patient_id,
            ]);
            $prescIdStmt = $pdo->prepare("SELECT prescription_id FROM prescriptions WHERE appointment_id = ?");
            $prescIdStmt->execute([$appointment_id]);
            $prescription_id = (int) $prescIdStmt->fetchColumn();

            // Replace items cleanly if the doctor is editing before completion.
            $pdo->prepare("DELETE FROM prescription_items WHERE prescription_id = ?")->execute([$prescription_id]);
            $itemStmt = $pdo->prepare("
                INSERT INTO prescription_items (prescription_id, medicine, dosage, frequency, duration, instructions)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($prescriptionItems as $item) {
                $itemStmt->execute([$prescription_id, $item['medicine'], $item['dosage'], $item['frequency'], $item['duration'], $item['instructions']]);
            }
        }

        if (!empty($labTests)) {
            $labStmt = $pdo->prepare("
                INSERT INTO lab_tests (appointment_id, doctor_id, patient_id, test_name, status)
                VALUES (?, ?, ?, ?, 'requested')
            ");
            foreach ($labTests as $testName) {
                $labStmt->execute([$appointment_id, $doctor_id, $patient_id, $testName]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not save consultation: ' . $e->getMessage()];
    }

    // Reuse the existing, unmodified completion workflow: token closed,
    // doctor freed up, next eligible token moves forward automatically.
    $result = complete_consultation($pdo, $doctor_id, $appointment_id);
    if (!$result['success']) {
        return $result;
    }

    add_notification($pdo, get_patient_user_id($pdo, $patient_id), 'Consultation completed for token ' . $appt['token'] . '. Your report is now available.');

    return ['success' => true, 'message' => 'Consultation saved and token completed.'];
}

function get_patient_user_id($pdo, $patient_id) {
    $stmt = $pdo->prepare("SELECT user_id FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * Full consultation record for one appointment: consultation notes,
 * prescription + items, lab tests. Used by the patient report page
 * and the doctor's "previous records" view. Returns null if there is
 * no consultation on file yet for this appointment.
 */
function get_consultation_record($pdo, $appointment_id) {
    $stmt = $pdo->prepare("SELECT * FROM consultations WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $consultation = $stmt->fetch();
    if (!$consultation) return null;

    $prescStmt = $pdo->prepare("SELECT * FROM prescriptions WHERE appointment_id = ?");
    $prescStmt->execute([$appointment_id]);
    $prescription = $prescStmt->fetch() ?: null;

    $items = [];
    if ($prescription) {
        $itemStmt = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY item_id ASC");
        $itemStmt->execute([$prescription['prescription_id']]);
        $items = $itemStmt->fetchAll();
    }

    $labStmt = $pdo->prepare("SELECT * FROM lab_tests WHERE appointment_id = ? ORDER BY lab_test_id ASC");
    $labStmt->execute([$appointment_id]);
    $labTests = $labStmt->fetchAll();

    return [
        'consultation' => $consultation,
        'prescription' => $prescription,
        'prescription_items' => $items,
        'lab_tests' => $labTests,
    ];
}

/**
 * A patient's own history of completed consultations (own records
 * only - callers must always scope by the logged-in patient_id).
 */
function get_patient_history($pdo, $patient_id, $exclude_appointment_id = null) {
    $sql = "
        SELECT a.appointment_id, a.token, a.appointment_date, a.appointment_time,
               a.completed_at, s.name AS specialization_name, d.full_name AS doctor_name
        FROM appointments a
        JOIN specializations s ON s.specialization_id = a.specialization_id
        LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
        WHERE a.patient_id = ? AND a.status = 'completed'
    ";
    $params = [$patient_id];
    if ($exclude_appointment_id) {
        $sql .= " AND a.appointment_id != ?";
        $params[] = $exclude_appointment_id;
    }
    $sql .= " ORDER BY a.completed_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------
// Pharmacy flow: Prescribed -> Sent to Pharmacy -> Processing ->
// Ready -> Dispensed. Doctor submitting already lands a
// prescription at 'sent_to_pharmacy' (see save_consultation_and_complete).
// Front-desk/admin staff move it forward from there - no separate
// pharmacy queue or algorithm, just a status column on the existing row.
// ---------------------------------------------------------
function pharmacy_next_status($current) {
    $order = ['prescribed', 'sent_to_pharmacy', 'processing', 'ready', 'dispensed'];
    $idx = array_search($current, $order, true);
    if ($idx === false || $idx >= count($order) - 1) return null;
    return $order[$idx + 1];
}

function update_prescription_status($pdo, $prescription_id, $newStatus) {
    $valid = ['prescribed', 'sent_to_pharmacy', 'processing', 'ready', 'dispensed'];
    if (!in_array($newStatus, $valid, true)) {
        return ['success' => false, 'message' => 'Invalid status.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE prescription_id = ?");
    $stmt->execute([$prescription_id]);
    $presc = $stmt->fetch();
    if (!$presc) return ['success' => false, 'message' => 'Prescription not found.'];

    $pdo->prepare("UPDATE prescriptions SET status = ? WHERE prescription_id = ?")->execute([$newStatus, $prescription_id]);

    $labels = ['prescribed' => 'Prescribed', 'sent_to_pharmacy' => 'Sent to Pharmacy', 'processing' => 'Processing', 'ready' => 'Ready for pickup', 'dispensed' => 'Dispensed'];
    $tokenStmt = $pdo->prepare("SELECT token FROM appointments WHERE appointment_id = ?");
    $tokenStmt->execute([$presc['appointment_id']]);
    $token = $tokenStmt->fetchColumn();
    add_notification($pdo, get_patient_user_id($pdo, $presc['patient_id']), 'Prescription for token ' . $token . ': ' . $labels[$newStatus] . '.');

    return ['success' => true];
}

function list_pharmacy_queue($pdo) {
    $stmt = $pdo->query("
        SELECT pr.*, a.token, p.full_name AS patient_name
        FROM prescriptions pr
        JOIN appointments a ON a.appointment_id = pr.appointment_id
        JOIN patients p ON p.patient_id = pr.patient_id
        WHERE pr.status != 'dispensed'
        ORDER BY pr.created_at ASC
    ");
    return $stmt->fetchAll();
}

// ---------------------------------------------------------
// Lab flow: Requested -> Processing -> Result Available.
// Same pattern as pharmacy - a status column on the existing row,
// no separate lab queue.
// ---------------------------------------------------------
function update_lab_status($pdo, $lab_test_id, $newStatus, $resultText = null) {
    $valid = ['requested', 'processing', 'result_available'];
    if (!in_array($newStatus, $valid, true)) {
        return ['success' => false, 'message' => 'Invalid status.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM lab_tests WHERE lab_test_id = ?");
    $stmt->execute([$lab_test_id]);
    $lab = $stmt->fetch();
    if (!$lab) return ['success' => false, 'message' => 'Lab test not found.'];

    if ($newStatus === 'result_available') {
        $pdo->prepare("UPDATE lab_tests SET status = ?, result_text = ? WHERE lab_test_id = ?")
            ->execute([$newStatus, trim((string) $resultText) ?: null, $lab_test_id]);
    } else {
        $pdo->prepare("UPDATE lab_tests SET status = ? WHERE lab_test_id = ?")->execute([$newStatus, $lab_test_id]);
    }

    $labels = ['requested' => 'Requested', 'processing' => 'Processing', 'result_available' => 'Result available'];
    $tokenStmt = $pdo->prepare("SELECT token FROM appointments WHERE appointment_id = ?");
    $tokenStmt->execute([$lab['appointment_id']]);
    $token = $tokenStmt->fetchColumn();
    add_notification($pdo, get_patient_user_id($pdo, $lab['patient_id']), 'Lab test "' . $lab['test_name'] . '" for token ' . $token . ': ' . $labels[$newStatus] . '.');

    return ['success' => true];
}

function list_lab_queue($pdo) {
    $stmt = $pdo->query("
        SELECT lt.*, a.token, p.full_name AS patient_name
        FROM lab_tests lt
        JOIN appointments a ON a.appointment_id = lt.appointment_id
        JOIN patients p ON p.patient_id = lt.patient_id
        WHERE lt.status != 'result_available'
        ORDER BY lt.requested_at ASC
    ");
    return $stmt->fetchAll();
}

function lab_next_status($current) {
    $order = ['requested', 'processing', 'result_available'];
    $idx = array_search($current, $order, true);
    if ($idx === false || $idx >= count($order) - 1) return null;
    return $order[$idx + 1];
}
