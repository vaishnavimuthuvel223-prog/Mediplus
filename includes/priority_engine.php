<?php
// =========================================================
// MediPlus - Priority Engine
// Transparent weighted scoring system (no machine learning).
// Deterministic tie-breaking is applied at the SQL ORDER BY
// level in recalculate_queue().
// =========================================================

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notification_engine.php';

/**
 * Calculates a transparent weighted priority score for a single
 * queue row. Higher score = higher priority.
 *
 * @param array $row  Row containing appointment + patient fields
 *                     (booking_emergency_level, age_category,
 *                      arrival_time, appointment_time, previous_status_flag)
 * @param bool  $has_active_emergency  Whether an unhandled Emergency
 *                     Request exists for this patient/appointment
 * @param PDO   $pdo
 * @return float
 */
function calculate_priority_score($row, $has_active_emergency, $pdo) {
    $score = 0.0;

    // 1. Emergency Button request - strongest possible influence
    if ($has_active_emergency) {
        $score += (float) get_setting('emergency_score', 1000);
    }

    // 2. Booking-time emergency level (set when appointment was made)
    if ($row['booking_emergency_level'] === 'critical') {
        $score += (float) get_setting('critical_booking_score', 300);
    } elseif ($row['booking_emergency_level'] === 'urgent') {
        $score += (float) get_setting('urgent_booking_score', 150);
    }

    // 3. Waiting time - gradually increases priority so normal
    //    patients are never starved indefinitely
    $waiting_minutes = minutes_between_now($row['arrival_time']);
    $score += $waiting_minutes * (float) get_setting('wait_weight_per_minute', 2);

    // 4. Age category
    if ($row['age_category'] === 'senior') {
        $score += (float) get_setting('senior_age_bonus', 20);
    } elseif ($row['age_category'] === 'child') {
        $score += (float) get_setting('child_age_bonus', 15);
    }

    // 5. Late arrival vs on-time - being late reduces priority slightly
    //    relative to punctual patients with the same wait time.
    if ($row['arrival_status'] === 'late') {
        $score -= 5;
    }

    // 6. Previous appointment status - a prior no-show does not block
    //    treatment but does not earn any bonus either.
    if ($row['previous_status_flag'] === 'no_show') {
        $score -= 3;
    }

    return round($score, 2);
}

/**
 * Recalculates priority scores and queue positions for every
 * active (arrived, waiting, or in_consultation) appointment.
 * Must be called after ANY event that can change ordering:
 * emergency trigger/handling, arrival, cancellation, completion,
 * doctor availability change, or elapsed waiting time.
 *
 * @param PDO $pdo
 * @return array Ordered queue rows (also persisted to queue_entries)
 */
function recalculate_queue($pdo) {
    $sql = "
        SELECT
            a.appointment_id, a.patient_id, a.doctor_id, a.specialization_id,
            a.token, a.booking_type, a.appointment_date, a.appointment_time,
            a.booking_emergency_level, a.arrival_status, a.arrival_time,
            a.status, a.previous_status_flag, a.created_at AS appt_created_at,
            a.consultation_started_at, a.travel_reminder_sent,
            p.full_name AS patient_name, p.age_category,
            qe.queue_id, qe.created_at AS queue_created_at,
            s.name AS specialization_name,
            d.full_name AS doctor_name
        FROM appointments a
        JOIN patients p ON p.patient_id = a.patient_id
        JOIN specializations s ON s.specialization_id = a.specialization_id
        LEFT JOIN queue_entries qe ON qe.appointment_id = a.appointment_id
        LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
        WHERE a.status IN ('arrived','waiting','in_consultation')
          AND a.appointment_date = CURDATE()
    ";
    $rows = $pdo->query($sql)->fetchAll();

    // Fetch active (unhandled) emergency requests keyed by appointment_id / patient_id
    $emergencyStmt = $pdo->query("
        SELECT appointment_id, patient_id FROM emergency_requests
        WHERE status != 'handled'
    ");
    $activeEmergencies = $emergencyStmt->fetchAll();
    $emergencyByAppointment = [];
    $emergencyByPatient = [];
    foreach ($activeEmergencies as $er) {
        if ($er['appointment_id']) $emergencyByAppointment[$er['appointment_id']] = true;
        $emergencyByPatient[$er['patient_id']] = true;
    }

    $scored = [];
    foreach ($rows as $row) {
        $hasEmergency = isset($emergencyByAppointment[$row['appointment_id']])
            || isset($emergencyByPatient[$row['patient_id']]);
        $row['priority_score'] = calculate_priority_score($row, $hasEmergency, $pdo);
        $row['has_emergency'] = $hasEmergency;
        $row['waiting_minutes'] = minutes_between_now($row['arrival_time']);
        $scored[] = $row;
    }

    // Deterministic tie-breaking:
    // 1. Higher priority score (which already embeds emergency weight)
    // 2. Longer waiting time
    // 3. Earlier appointment time
    // 4. Earlier arrival time
    // 5. Earlier queue creation time
    usort($scored, function ($a, $b) {
        if ($a['priority_score'] != $b['priority_score']) {
            return $b['priority_score'] <=> $a['priority_score'];
        }
        if ($a['waiting_minutes'] != $b['waiting_minutes']) {
            return $b['waiting_minutes'] <=> $a['waiting_minutes'];
        }
        $apptTimeCmp = strcmp($a['appointment_time'], $b['appointment_time']);
        if ($apptTimeCmp !== 0) return $apptTimeCmp;

        $aArrival = $a['arrival_time'] ?? '9999-12-31 23:59:59';
        $bArrival = $b['arrival_time'] ?? '9999-12-31 23:59:59';
        $arrivalCmp = strcmp($aArrival, $bArrival);
        if ($arrivalCmp !== 0) return $arrivalCmp;

        $aQueueCreated = $a['queue_created_at'] ?? $a['appt_created_at'];
        $bQueueCreated = $b['queue_created_at'] ?? $b['appt_created_at'];
        return strcmp($aQueueCreated, $bQueueCreated);
    });

    // Persist position + score, upserting queue_entries rows
    $position = 0;
    $upsert = $pdo->prepare("
        INSERT INTO queue_entries (appointment_id, priority_score, queue_position, queue_status)
        VALUES (:appointment_id, :priority_score, :queue_position, :queue_status)
        ON DUPLICATE KEY UPDATE
            priority_score = VALUES(priority_score),
            queue_position = VALUES(queue_position),
            queue_status = VALUES(queue_status)
    ");
    foreach ($scored as &$row) {
        $position++;
        $row['queue_position'] = $position;
        $queueStatus = $row['status'] === 'in_consultation' ? 'in_consultation' : 'waiting';
        $upsert->execute([
            ':appointment_id' => $row['appointment_id'],
            ':priority_score' => $row['priority_score'],
            ':queue_position' => $position,
            ':queue_status' => $queueStatus,
        ]);
    }
    unset($row);

    // Fire "get ready" / travel reminders based on the freshly
    // computed order. Best-effort: never blocks or breaks the queue
    // itself if a reminder can't be sent for some reason.
    try {
        send_token_proximity_reminders($pdo, $scored);
    } catch (Exception $e) {
        // Reminders are a convenience layer; a failure here must never
        // take down the live queue page or booking flow.
    }

    return $scored;
}

/**
 * Returns estimated waiting time in minutes for a given appointment
 * based on the number of active patients ahead of it in the queue.
 */
function estimate_waiting_time($pdo, $appointment_id) {
    $queue = recalculate_queue($pdo);
    $avgDuration = (int) get_setting('avg_consultation_minutes', 15);

    $patientsAhead = 0;
    $found = false;
    foreach ($queue as $row) {
        if ((int)$row['appointment_id'] === (int)$appointment_id) {
            $found = true;
            break;
        }
        if ($row['status'] !== 'in_consultation') {
            $patientsAhead++;
        }
    }

    if (!$found) {
        return ['patients_ahead' => 0, 'estimated_minutes' => 0, 'position' => null];
    }

    return [
        'patients_ahead' => $patientsAhead,
        'estimated_minutes' => $patientsAhead * $avgDuration,
    ];
}

/**
 * Full patient-facing summary for a given appointment: queue
 * position, patients ahead, estimated wait, and (if the patient
 * has a usable address) a travel estimate + reach-by/leave-by
 * clock times. Used by the patient dashboard and the polling
 * queue_status endpoint so both stay in sync with one source
 * of truth.
 */
function get_patient_queue_summary($pdo, $appointment_id) {
    $wait = estimate_waiting_time($pdo, $appointment_id);

    $summary = [
        'patients_ahead' => $wait['patients_ahead'],
        'estimated_minutes' => $wait['estimated_minutes'],
        'travel' => null,
        'schedule' => null,
    ];

    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch();
    if (!$appt) return $summary;

    $patientStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$appt['patient_id']]);
    $patient = $patientStmt->fetch();
    if (!$patient) return $summary;

    $travel = get_travel_estimate($pdo, $patient);
    if ($travel) {
        $summary['travel'] = $travel;
        $summary['schedule'] = calculate_reach_by_schedule($wait['estimated_minutes'], $travel['travel_minutes']);
    }

    return $summary;
}

// =========================================================
// Patient Token Board (Patient Module: booking confirmation +
// live queue display). Read-only helpers built entirely on top
// of the existing recalculate_queue()/estimate_waiting_time()/
// calculate_reach_by_schedule() engine above - they do not
// change scoring, ordering, or persistence in any way.
// =========================================================

/**
 * Determines the hospital-wide "Current Token" (the token presently
 * in consultation, or the most recently completed one if nobody is
 * in consultation right now) and the "Next Token" (the token at the
 * front of the live queue). Reuses the already-ordered $queue array
 * from recalculate_queue() instead of re-deriving any ordering.
 *
 * @param PDO $pdo
 * @param array|null $queue  Optional pre-fetched recalculate_queue() result
 * @return array ['current_token' => string|null, 'next_token' => string|null]
 */
function get_current_and_next_token($pdo, $queue = null) {
    if ($queue === null) {
        $queue = recalculate_queue($pdo);
    }

    $current = null;
    $latestStart = null;
    foreach ($queue as $row) {
        if ($row['status'] === 'in_consultation') {
            $started = $row['consultation_started_at'] ?? null;
            if ($current === null || ($started && $started > $latestStart)) {
                $current = $row['token'];
                $latestStart = $started;
            }
        }
    }

    // Nobody currently in consultation - fall back to the most recently
    // completed token today so the board never looks blank between calls.
    if (!$current) {
        $lastCompletedStmt = $pdo->prepare("
            SELECT token FROM appointments
            WHERE appointment_date = CURDATE() AND status = 'completed'
            ORDER BY completed_at DESC LIMIT 1
        ");
        $lastCompletedStmt->execute();
        $current = $lastCompletedStmt->fetchColumn() ?: null;
    }

    // $queue is already in priority order, so the first waiting/arrived
    // row is exactly the next token to be called.
    $next = null;
    foreach ($queue as $row) {
        if (in_array($row['status'], ['arrived', 'waiting'], true)) {
            $next = $row['token'];
            break;
        }
    }

    return ['current_token' => $current, 'next_token' => $next];
}

/**
 * Builds the full "token board" a patient sees for one of their own
 * appointments: their token, the current/next hospital tokens, how
 * many patients are ahead of them, their queue position, and an
 * estimated consultation clock time. Used right after booking (before
 * arrival) and by the live-polling queue status endpoint (after
 * arrival), so both views always agree with each other and with the
 * doctor/nurse/admin queue.
 *
 * @param PDO $pdo
 * @param int $appointment_id
 * @return array|null  null if the appointment does not exist
 */
function get_patient_token_board($pdo, $appointment_id) {
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $appt = $stmt->fetch();
    if (!$appt) return null;

    $queue = recalculate_queue($pdo);
    $tokens = get_current_and_next_token($pdo, $queue);
    $avgDuration = (int) get_setting('avg_consultation_minutes', 15);

    $board = [
        'appointment_id' => (int) $appt['appointment_id'],
        'token' => $appt['token'],
        'appointment_date' => $appt['appointment_date'],
        'appointment_time' => $appt['appointment_time'],
        'status' => $appt['status'],
        'current_token' => $tokens['current_token'],
        'next_token' => $tokens['next_token'],
        'patients_ahead' => 0,
        'queue_position' => null,
        'estimated_minutes' => 0,
        'estimated_time' => null,
        'in_active_queue' => false,
    ];

    if (in_array($appt['status'], ['arrived', 'waiting', 'in_consultation'], true)) {
        // Already part of today's live queue - use the exact figures
        // the priority engine itself computed, no re-derivation.
        $wait = estimate_waiting_time($pdo, $appointment_id);
        $board['patients_ahead'] = $wait['patients_ahead'];
        $board['estimated_minutes'] = $wait['estimated_minutes'];
        $board['in_active_queue'] = true;
        foreach ($queue as $row) {
            if ((int) $row['appointment_id'] === (int) $appointment_id) {
                $board['queue_position'] = (int) $row['queue_position'];
                break;
            }
        }
    } elseif ($appt['status'] === 'scheduled' && $appt['appointment_date'] === date('Y-m-d')) {
        // Booked for today but not arrived yet - preview their position
        // as "everyone currently active is ahead of me", which becomes
        // exact the moment they arrive and join the real queue.
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt FROM appointments
            WHERE appointment_date = CURDATE() AND status IN ('arrived','waiting','in_consultation')
        ");
        $countStmt->execute();
        $ahead = (int) $countStmt->fetch()['cnt'];
        $board['patients_ahead'] = $ahead;
        $board['queue_position'] = $ahead + 1;
        $board['estimated_minutes'] = $ahead * $avgDuration;
    }

    $schedule = calculate_reach_by_schedule($board['estimated_minutes'], 0);
    $board['estimated_time'] = $schedule['expected_call_time'];

    return $board;
}
