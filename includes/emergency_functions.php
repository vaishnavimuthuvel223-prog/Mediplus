<?php
// =========================================================
// MediPlus - Emergency Request Lifecycle
// Emergency Triggered -> Priority Assigned -> Hospital Alert ->
// Doctor/Staff Assignment -> Location Available ->
// Response In Progress -> Handled
// =========================================================

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/priority_engine.php';
require_once __DIR__ . '/appointment_functions.php';

/**
 * Triggers a new Emergency Request for a patient. Handles the case
 * where the patient already has an active (non-completed) appointment
 * today, and also the case where they have none (walk-in emergency).
 */
function trigger_emergency($pdo, $patient_id, $specialization_id = null) {
    // Prevent duplicate active emergencies for the same patient
    $existing = $pdo->prepare("SELECT * FROM emergency_requests WHERE patient_id = ? AND status != 'handled'");
    $existing->execute([$patient_id]);
    if ($row = $existing->fetch()) {
        return ['success' => true, 'emergency_id' => $row['emergency_id'], 'already_active' => true];
    }

    // Find an existing active appointment today for this patient, else create one
    $apptStmt = $pdo->prepare("
        SELECT * FROM appointments
        WHERE patient_id = ? AND appointment_date = CURDATE()
          AND status IN ('scheduled','arrived','waiting','in_consultation')
        ORDER BY created_at DESC LIMIT 1
    ");
    $apptStmt->execute([$patient_id]);
    $appt = $apptStmt->fetch();

    if (!$appt) {
        // Walk-in emergency with no prior booking - default to Emergency Medicine
        if (!$specialization_id) {
            $specStmt = $pdo->query("SELECT specialization_id FROM specializations WHERE name = 'Emergency Medicine Specialist' LIMIT 1");
            $specRow = $specStmt->fetch();
            $specialization_id = $specRow ? $specRow['specialization_id'] : 1;
        }
        $appointment_id = book_appointment($pdo, $patient_id, $specialization_id, null, date('Y-m-d'), date('H:i:s'), 'critical');
        mark_patient_arrival($pdo, $appointment_id);
    } else {
        $appointment_id = $appt['appointment_id'];
        $specialization_id = $specialization_id ?: $appt['specialization_id'];
        if ($appt['arrival_status'] === 'not_arrived') {
            mark_patient_arrival($pdo, $appointment_id);
        }
        // Force the appointment's own emergency level up as well
        $pdo->prepare("UPDATE appointments SET booking_emergency_level = 'critical' WHERE appointment_id = ?")
            ->execute([$appointment_id]);
    }

    $ins = $pdo->prepare("
        INSERT INTO emergency_requests (patient_id, appointment_id, specialization_id, status, triggered_at)
        VALUES (?, ?, ?, 'priority_assigned', NOW())
    ");
    $ins->execute([$patient_id, $appointment_id, $specialization_id]);
    $emergency_id = (int) $pdo->lastInsertId();

    // Immediate recalculation - emergency jumps to top of queue
    recalculate_queue($pdo);

    // Hospital Alert stage
    $pdo->prepare("UPDATE emergency_requests SET status = 'staff_alerted' WHERE emergency_id = ?")->execute([$emergency_id]);
    notify_staff_of_emergency($pdo, $emergency_id, $patient_id);

    // Attempt immediate doctor/staff assignment
    attempt_doctor_assignment($pdo, $appointment_id);
    $apptCheck = $pdo->prepare("SELECT doctor_id FROM appointments WHERE appointment_id = ?");
    $apptCheck->execute([$appointment_id]);
    $doctorAssigned = $apptCheck->fetch()['doctor_id'] ?? null;
    if ($doctorAssigned) {
        $pdo->prepare("UPDATE emergency_requests SET status = 'doctor_assigned' WHERE emergency_id = ?")->execute([$emergency_id]);
    }

    return ['success' => true, 'emergency_id' => $emergency_id, 'appointment_id' => $appointment_id, 'already_active' => false];
}

/**
 * Notifies all currently authorized hospital staff (doctors, nurses,
 * admins) of a new emergency.
 */
function notify_staff_of_emergency($pdo, $emergency_id, $patient_id) {
    $patientStmt = $pdo->prepare("SELECT full_name FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$patient_id]);
    $patientName = $patientStmt->fetch()['full_name'] ?? 'A patient';

    $message = "EMERGENCY ALERT: {$patientName} triggered the emergency button. Immediate attention required.";

    $staffStmt = $pdo->query("SELECT user_id FROM users WHERE role IN ('doctor','nurse','admin','management') AND is_active = 1");
    $insert = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    foreach ($staffStmt->fetchAll() as $staff) {
        $insert->execute([$staff['user_id'], $message]);
    }
}

/**
 * Stores a consent-based location update for an active emergency.
 */
function store_emergency_location($pdo, $emergency_id, $latitude, $longitude) {
    $check = $pdo->prepare("SELECT * FROM emergency_requests WHERE emergency_id = ?");
    $check->execute([$emergency_id]);
    if (!$check->fetch()) {
        return ['success' => false, 'message' => 'Emergency request not found.'];
    }

    $ins = $pdo->prepare("
        INSERT INTO emergency_locations (emergency_id, latitude, longitude, captured_at)
        VALUES (?, ?, ?, NOW())
    ");
    $ins->execute([$emergency_id, $latitude, $longitude]);

    return ['success' => true];
}

/**
 * Returns the latest known location for an emergency, if any.
 */
function get_latest_emergency_location($pdo, $emergency_id) {
    $stmt = $pdo->prepare("
        SELECT latitude, longitude, captured_at FROM emergency_locations
        WHERE emergency_id = ? ORDER BY captured_at DESC LIMIT 1
    ");
    $stmt->execute([$emergency_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Marks an emergency as handled once the patient's consultation
 * is complete. Also called automatically from complete_consultation().
 */
function handle_emergency_completion($pdo, $emergency_id) {
    $pdo->prepare("UPDATE emergency_requests SET status = 'handled', handled_at = NOW() WHERE emergency_id = ?")
        ->execute([$emergency_id]);
    recalculate_queue($pdo);
    return ['success' => true];
}

/**
 * Returns all currently active (unhandled) emergencies with patient
 * and location info, for the staff alert dashboard.
 */
function get_active_emergencies($pdo) {
    $stmt = $pdo->query("
        SELECT er.*, p.full_name AS patient_name, p.phone,
               a.token, a.status AS appointment_status, s.name AS specialization_name
        FROM emergency_requests er
        JOIN patients p ON p.patient_id = er.patient_id
        LEFT JOIN appointments a ON a.appointment_id = er.appointment_id
        LEFT JOIN specializations s ON s.specialization_id = er.specialization_id
        WHERE er.status != 'handled'
        ORDER BY er.triggered_at ASC
    ");
    $emergencies = $stmt->fetchAll();
    foreach ($emergencies as &$em) {
        $em['location'] = get_latest_emergency_location($pdo, $em['emergency_id']);
    }
    unset($em);
    return $emergencies;
}
