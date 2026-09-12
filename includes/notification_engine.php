<?php
// =========================================================
// MediPlus - Reminder Engine
//
// "2 tokens to go, get ready" style reminders for patients
// waiting in the live queue, plus a travel reminder telling
// them when to leave home. Called automatically every time
// the queue is recalculated (booking, arrival, completion,
// emergency, doctor availability change, or a page/poll
// refresh) - no separate cron job required.
// =========================================================

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/travel_functions.php';

/**
 * Parses the configurable "reminder_thresholds" setting
 * (e.g. "3,2,1,0") into a descending array of ints.
 */
function get_reminder_thresholds() {
    $raw = get_setting('reminder_thresholds', '3,2,1,0');
    $thresholds = array_filter(array_map('intval', explode(',', $raw)), function ($v) { return $v >= 0; });
    $thresholds = array_values(array_unique($thresholds));
    rsort($thresholds);
    return $thresholds ?: [3, 2, 1, 0];
}

/**
 * Given the already-computed, position-ordered queue (from
 * recalculate_queue()), sends "get ready" notifications to
 * patients whose position has just crossed a configured
 * threshold, and a one-time travel reminder telling them when
 * to leave. Safe to call as often as needed - every reminder
 * is recorded so it is only ever sent once.
 */
function send_token_proximity_reminders($pdo, array $queue) {
    $thresholds = get_reminder_thresholds();
    $avgDuration = (int) get_setting('avg_consultation_minutes', 15);

    $alreadySentStmt = $pdo->prepare("SELECT threshold FROM token_reminders_sent WHERE appointment_id = ?");
    $insertSent = $pdo->prepare("INSERT IGNORE INTO token_reminders_sent (appointment_id, threshold) VALUES (?, ?)");

    $patientsAhead = 0;
    foreach ($queue as $row) {
        if ($row['status'] === 'in_consultation') {
            // Doesn't count toward anyone else's "ahead" total, and
            // doesn't need a proximity reminder itself.
            continue;
        }

        // ---- Token proximity reminder ("2 tokens ahead, get ready") ----
        $alreadySentStmt->execute([$row['appointment_id']]);
        $sent = array_map('intval', array_column($alreadySentStmt->fetchAll(), 'threshold'));

        foreach ($thresholds as $threshold) {
            if (in_array($threshold, $sent, true)) continue;
            if ($patientsAhead > $threshold) continue;

            if ($threshold === 0) {
                $message = "It's almost your turn (token {$row['token']}) - please be at the consultation area now.";
            } elseif ($threshold === 1) {
                $message = "Only 1 patient ahead of you (token {$row['token']}) - please get ready.";
            } else {
                $message = "{$threshold} tokens ahead of you (token {$row['token']}) - get ready, your turn is coming up soon.";
            }

            $patientUserId = get_user_id_for_patient($pdo, $row['patient_id']);
            if ($patientUserId) {
                add_notification($pdo, $patientUserId, $message);
            }
            $insertSent->execute([$row['appointment_id'], $threshold]);

            // Only the single most relevant (highest still-unsent) threshold
            // fires per cycle, so patients don't get several messages at once
            // if the queue jumps suddenly.
            break;
        }

        // ---- Travel / "leave now" reminder (one-time per appointment) ----
        if (!$row['travel_reminder_sent']) {
            $estimatedWaitMinutes = $patientsAhead * $avgDuration;
            maybe_send_travel_reminder($pdo, $row, $estimatedWaitMinutes);
        }

        $patientsAhead++;
    }
}

/**
 * Sends a one-time "leave now to reach by HH:MM" notification once
 * the patient's calculated leave-by time has arrived. Requires the
 * patient to have a geocoded address; silently skips otherwise.
 */
function maybe_send_travel_reminder($pdo, array $row, $estimatedWaitMinutes) {
    $patientStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $patientStmt->execute([$row['patient_id']]);
    $patient = $patientStmt->fetch();
    if (!$patient || empty($patient['address'])) return;

    $travel = get_travel_estimate($pdo, $patient);
    if (!$travel) return;

    $schedule = calculate_reach_by_schedule($estimatedWaitMinutes, $travel['travel_minutes']);
    if (!$schedule['leave_by_is_now']) return;

    $message = "Time to head to the hospital for token {$row['token']} - travel takes about {$travel['travel_minutes']} min. Aim to reach by {$schedule['reach_by_time']}.";
    if ($patient['user_id']) {
        add_notification($pdo, $patient['user_id'], $message);
    }

    $pdo->prepare("UPDATE appointments SET travel_reminder_sent = 1 WHERE appointment_id = ?")
        ->execute([$row['appointment_id']]);
}

function get_user_id_for_patient($pdo, $patient_id) {
    static $cache = [];
    if (isset($cache[$patient_id])) return $cache[$patient_id];
    $stmt = $pdo->prepare("SELECT user_id FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $row = $stmt->fetch();
    $cache[$patient_id] = $row ? (int) $row['user_id'] : null;
    return $cache[$patient_id];
}
