<?php
// =========================================================
// MediPlus - Appointment Lifecycle & Doctor Assignment
// =========================================================

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/priority_engine.php';

/**
 * Books a new appointment for a patient.
 *
 * @param string $booking_type    'online' (patient self-booked, default)
 *                                 or 'walk_in' (added manually by admin/nurse)
 * @param int|null $added_by_admin_id  admin_id who added a walk-in token, if any
 */
function book_appointment($pdo, $patient_id, $specialization_id, $doctor_id, $date, $time, $emergency_level, $booking_type = 'online', $added_by_admin_id = null) {
    // Previous no-show detection for this patient
    $prevStmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt FROM appointments
        WHERE patient_id = ? AND status = 'scheduled' AND arrival_status = 'not_arrived'
          AND appointment_date < CURDATE()
    ");
    $prevStmt->execute([$patient_id]);
    $prevRow = $prevStmt->fetch();
    $previousFlag = ((int)$prevRow['cnt'] > 0) ? 'no_show' : 'none';

    if (!in_array($booking_type, ['online', 'walk_in'], true)) {
        $booking_type = 'online';
    }
    $token = generate_token($pdo, $booking_type);

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (patient_id, doctor_id, specialization_id, token, booking_type, added_by_admin_id,
             appointment_date, appointment_time, booking_emergency_level, arrival_status, status, previous_status_flag)
        VALUES
            (:patient_id, :doctor_id, :specialization_id, :token, :booking_type, :added_by_admin_id,
             :appointment_date, :appointment_time, :emergency_level, 'not_arrived', 'scheduled', :previous_flag)
    ");
    $stmt->execute([
        ':patient_id' => $patient_id,
        ':doctor_id' => $doctor_id ?: null,
        ':specialization_id' => $specialization_id,
        ':token' => $token,
        ':booking_type' => $booking_type,
        ':added_by_admin_id' => $added_by_admin_id ?: null,
        ':appointment_date' => $date,
        ':appointment_time' => $time,
        ':emergency_level' => $emergency_level,
        ':previous_flag' => $previousFlag,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Admin/front-desk workflow for a patient who walks in without any
 * prior online booking or token. Finds the patient by phone (or
 * creates a minimal patient record if truly new), books an
 * appointment marked booking_type='walk_in', and immediately marks
 * them arrived so they drop straight into today's live queue in
 * correct priority order alongside online bookings - the doctor
 * sees one unified sequential queue either way.
 *
 * @return array ['success' => bool, 'message' => string, 'appointment_id' => int|null, 'token' => string|null]
 */
function add_walk_in_token($pdo, $admin_id, $full_name, $phone, $specialization_id, $doctor_id = null, $emergency_level = 'normal', $dob = null, $gender = 'other', $address = null) {
    $phone = trim((string) $phone);
    $full_name = trim((string) $full_name);
    if ($full_name === '' || $phone === '' || !$specialization_id) {
        return ['success' => false, 'message' => 'Name, phone, and specialization are required.', 'appointment_id' => null, 'token' => null];
    }

    $pdo->beginTransaction();
    try {
        // Reuse an existing patient record by phone number if one exists,
        // so repeat walk-in patients don't get duplicated.
        $findStmt = $pdo->prepare("SELECT patient_id FROM patients WHERE phone = ? ORDER BY patient_id DESC LIMIT 1");
        $findStmt->execute([$phone]);
        $existing = $findStmt->fetch();

        if ($existing) {
            $patient_id = (int) $existing['patient_id'];
        } else {
            // Minimal walk-in patient record. No login account is created;
            // this only exists so the appointment/queue system (which is
            // keyed on patient_id) can track them like any other patient.
            $walkInUserStmt = $pdo->prepare("
                INSERT INTO users (role, username, email, password_hash, is_active)
                VALUES ('patient', :username, :email, :hash, 1)
            ");
            $uniqueSuffix = bin2hex(random_bytes(4));
            $walkInUserStmt->execute([
                ':username' => 'walkin_' . $uniqueSuffix,
                ':email' => 'walkin_' . $uniqueSuffix . '@mediplus.local',
                ':hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            ]);
            $user_id = (int) $pdo->lastInsertId();

            $safeDob = $dob ?: date('Y-m-d', strtotime('-30 years'));
            $ageCategory = calculate_age_category($safeDob);

            $patientStmt = $pdo->prepare("
                INSERT INTO patients (user_id, full_name, dob, age_category, gender, phone, address)
                VALUES (:user_id, :full_name, :dob, :age_category, :gender, :phone, :address)
            ");
            $patientStmt->execute([
                ':user_id' => $user_id,
                ':full_name' => $full_name,
                ':dob' => $safeDob,
                ':age_category' => $ageCategory,
                ':gender' => in_array($gender, ['male','female','other'], true) ? $gender : 'other',
                ':phone' => $phone,
                ':address' => $address ?: null,
            ]);
            $patient_id = (int) $pdo->lastInsertId();
        }

        $appointment_id = book_appointment(
            $pdo, $patient_id, $specialization_id, $doctor_id, date('Y-m-d'), date('H:i:s'),
            in_array($emergency_level, ['normal','urgent','critical'], true) ? $emergency_level : 'normal',
            'walk_in', $admin_id
        );

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Could not add walk-in token: ' . $e->getMessage(), 'appointment_id' => null, 'token' => null];
    }

    // Walk-in patients are, by definition, already physically at the
    // hospital - drop them straight into the live queue.
    $arrival = mark_patient_arrival($pdo, $appointment_id);
    if (!$arrival['success']) {
        return ['success' => false, 'message' => $arrival['message'], 'appointment_id' => $appointment_id, 'token' => null];
    }

    $tokenStmt = $pdo->prepare("SELECT token FROM appointments WHERE appointment_id = ?");
    $tokenStmt->execute([$appointment_id]);
    $token = $tokenStmt->fetchColumn();

    return ['success' => true, 'message' => 'Walk-in token created.', 'appointment_id' => $appointment_id, 'token' => $token];
}

/**
 * Marks a patient as arrived, moving them into the live queue.
 * Detects late arrival relative to appointment_time.
 */
function mark_patient_arrival($pdo, $appointment_id) {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch();

    if (!$appt) return ['success' => false, 'message' => 'Appointment not found.'];
    if ($appt['status'] === 'completed') return ['success' => false, 'message' => 'This appointment is already completed.'];
    if ($appt['status'] === 'cancelled') return ['success' => false, 'message' => 'This appointment was cancelled.'];
    if ($appt['arrival_status'] !== 'not_arrived') return ['success' => false, 'message' => 'Arrival already recorded.'];

    $now = new DateTime();
    $apptDateTime = new DateTime($appt['appointment_date'] . ' ' . $appt['appointment_time']);
    $isLate = $now > (clone $apptDateTime)->modify('+10 minutes');

    $arrivalStatus = $isLate ? 'late' : 'arrived';

    $update = $pdo->prepare("
        UPDATE appointments
        SET arrival_status = :arrival_status, arrival_time = NOW(), status = 'waiting'
        WHERE appointment_id = :id
    ");
    $update->execute([':arrival_status' => $arrivalStatus, ':id' => $appointment_id]);

    // Insert queue entry if not present
    $qStmt = $pdo->prepare("SELECT queue_id FROM queue_entries WHERE appointment_id = ?");
    $qStmt->execute([$appointment_id]);
    if (!$qStmt->fetch()) {
        $ins = $pdo->prepare("INSERT INTO queue_entries (appointment_id, priority_score, queue_status) VALUES (?, 0, 'waiting')");
        $ins->execute([$appointment_id]);
    }

    recalculate_queue($pdo);
    attempt_doctor_assignment($pdo, $appointment_id);

    return ['success' => true, 'late' => $isLate];
}

/**
 * Cancels an appointment. Cancelled appointments are never deleted
 * and never re-enter the active queue.
 */
function cancel_appointment($pdo, $appointment_id, $patient_id = null) {
    $where = 'appointment_id = :id';
    $params = [':id' => $appointment_id];
    if ($patient_id !== null) {
        $where .= ' AND patient_id = :patient_id';
        $params[':patient_id'] = $patient_id;
    }

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE $where");
    $stmt->execute($params);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not found.'];
    if ($appt['status'] === 'completed') return ['success' => false, 'message' => 'A completed appointment cannot be cancelled.'];
    if ($appt['status'] === 'cancelled') return ['success' => false, 'message' => 'Appointment already cancelled.'];

    $update = $pdo->prepare("UPDATE appointments SET status = 'cancelled', cancelled_at = NOW() WHERE appointment_id = ?");
    $update->execute([$appointment_id]);

    $qUpdate = $pdo->prepare("UPDATE queue_entries SET queue_status = 'cancelled' WHERE appointment_id = ?");
    $qUpdate->execute([$appointment_id]);

    // If the doctor was busy specifically because of this patient, free them up
    if ($appt['doctor_id'] && $appt['status'] === 'in_consultation') {
        $freeDoctor = $pdo->prepare("UPDATE doctors SET status = 'available' WHERE doctor_id = ?");
        $freeDoctor->execute([$appt['doctor_id']]);
    }

    recalculate_queue($pdo);
    return ['success' => true];
}

/**
 * Attempts to assign the highest-priority waiting patient requiring
 * a given specialization to an available doctor of that specialization.
 * Also used to try to assign a specific appointment when possible.
 */
function attempt_doctor_assignment($pdo, $appointment_id = null) {
    $queue = recalculate_queue($pdo);

    foreach ($queue as $row) {
        if ($row['status'] !== 'waiting' && $row['status'] !== 'arrived') continue;
        if ($appointment_id !== null && (int)$row['appointment_id'] !== (int)$appointment_id) continue;

        // Already has a doctor assigned and doctor is available -> nothing to do
        $doctor = find_available_doctor($pdo, $row['specialization_id'], $row['doctor_id']);

        if (!$doctor) {
            // No suitable doctor free right now - leave as "Waiting for Specialist"
            continue;
        }

        $assign = $pdo->prepare("UPDATE appointments SET doctor_id = ?, status = 'waiting' WHERE appointment_id = ?");
        $assign->execute([$doctor['doctor_id'], $row['appointment_id']]);
    }

    recalculate_queue($pdo);
}

/**
 * Finds an available doctor for a given specialization.
 * If a preferred doctor_id is already assigned and available, keep them.
 */
function find_available_doctor($pdo, $specialization_id, $preferred_doctor_id = null) {
    if ($preferred_doctor_id) {
        $stmt = $pdo->prepare("SELECT * FROM doctors WHERE doctor_id = ? AND status = 'available' AND specialization_id = ?");
        $stmt->execute([$preferred_doctor_id, $specialization_id]);
        $doc = $stmt->fetch();
        if ($doc) return $doc;
    }

    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE specialization_id = ? AND status = 'available' ORDER BY doctor_id ASC LIMIT 1");
    $stmt->execute([$specialization_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Doctor starts consultation with the next (highest priority) patient
 * in their own queue, or a specific appointment.
 */
function start_consultation($pdo, $doctor_id, $appointment_id) {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND doctor_id = ?");
    $stmt->execute([$appointment_id, $doctor_id]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not assigned to this doctor.'];
    if (!in_array($appt['status'], ['waiting', 'arrived'])) {
        return ['success' => false, 'message' => 'Patient is not in a waiting state.'];
    }

    $pdo->prepare("UPDATE appointments SET status = 'in_consultation', consultation_started_at = NOW() WHERE appointment_id = ?")->execute([$appointment_id]);
    $pdo->prepare("UPDATE doctors SET status = 'busy' WHERE doctor_id = ?")->execute([$doctor_id]);
    $pdo->prepare("UPDATE queue_entries SET queue_status = 'in_consultation' WHERE appointment_id = ?")->execute([$appointment_id]);

    // If this was an emergency, move it toward "in_progress"
    $pdo->prepare("
        UPDATE emergency_requests SET status = 'in_progress'
        WHERE appointment_id = ? AND status != 'handled'
    ")->execute([$appointment_id]);

    recalculate_queue($pdo);
    return ['success' => true];
}

/**
 * Doctor completes a consultation. Completed patients never re-enter
 * the active queue. Doctor becomes available again and assignment
 * runs immediately for the next patient.
 */
function complete_consultation($pdo, $doctor_id, $appointment_id) {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ? AND doctor_id = ?");
    $stmt->execute([$appointment_id, $doctor_id]);
    $appt = $stmt->fetch();
    if (!$appt) return ['success' => false, 'message' => 'Appointment not assigned to this doctor.'];
    if ($appt['status'] !== 'in_consultation') {
        return ['success' => false, 'message' => 'Patient is not currently in consultation.'];
    }

    $pdo->prepare("UPDATE appointments SET status = 'completed', completed_at = NOW() WHERE appointment_id = ?")->execute([$appointment_id]);
    $pdo->prepare("UPDATE doctors SET status = 'available' WHERE doctor_id = ?")->execute([$doctor_id]);
    $pdo->prepare("UPDATE queue_entries SET queue_status = 'completed' WHERE appointment_id = ?")->execute([$appointment_id]);

    $pdo->prepare("
        UPDATE emergency_requests SET status = 'handled', handled_at = NOW()
        WHERE appointment_id = ? AND status != 'handled'
    ")->execute([$appointment_id]);

    recalculate_queue($pdo);
    attempt_doctor_assignment($pdo);

    return ['success' => true];
}
