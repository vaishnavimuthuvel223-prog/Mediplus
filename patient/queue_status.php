<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];

$appointment_id = $_GET['appointment_id'] ?? null;

if (!$appointment_id) {
    json_response(['success' => false, 'message' => 'appointment_id required'], 400);
}

// Ownership check
$check = $pdo->prepare("SELECT patient_id FROM appointments WHERE appointment_id = ?");
$check->execute([$appointment_id]);
$owner = $check->fetch();
if (!$owner || (int)$owner['patient_id'] !== (int)$patient_id) {
    json_response(['success' => false, 'message' => 'Not found.'], 404);
}

$queue = recalculate_queue($pdo);
$position = null;
$score = null;
$status = null;
foreach ($queue as $row) {
    if ((int)$row['appointment_id'] === (int)$appointment_id) {
        $position = (int) $row['queue_position'];
        $score = $row['priority_score'];
        $status = $row['status'];
        break;
    }
}

$summary = get_patient_queue_summary($pdo, $appointment_id);
$board = get_patient_token_board($pdo, $appointment_id);

// get_patient_token_board() covers BOTH "already in the active queue"
// (identical numbers to $summary/estimate_waiting_time) AND "booked for
// today, not arrived yet" (a live preview that $summary/estimate_waiting_time
// don't know about, since that function only looks at the active queue).
// Prefer it so patients_ahead/estimated_minutes/queue_position are always
// meaningful, from the moment of booking onward.
$patientsAhead = $board['patients_ahead'];
$estimatedMinutes = $board['estimated_minutes'];
$queuePosition = $position !== null ? $position : $board['queue_position'];

$proximityMessage = null;
if ($status === 'in_consultation') {
    $proximityMessage = "You're with the doctor now.";
} elseif ($patientsAhead <= 2) {
    $proximityMessage = $patientsAhead === 0
        ? "It's almost your turn — please be ready."
        : ($patientsAhead . ' token(s) ahead of you — get ready!');
}

json_response([
    'success' => true,
    'queue_position' => $queuePosition,
    'priority_score' => $score,
    'status' => $status,
    'patients_ahead' => $patientsAhead,
    'estimated_minutes' => $estimatedMinutes,
    'proximity_message' => $proximityMessage,
    'travel' => $summary['travel'],
    'schedule' => $summary['schedule'],
    // Token board (live queue): current/next hospital token and this
    // patient's own token, so the dashboard and confirmation page can
    // poll a single endpoint for everything they display.
    'token' => $board['token'] ?? null,
    'current_token' => $board['current_token'] ?? null,
    'next_token' => $board['next_token'] ?? null,
    'appointment_date' => $board['appointment_date'] ?? null,
    'appointment_time' => $board['appointment_time'] ?? null,
    'estimated_time' => $board['estimated_time'] ?? null,
    // Actual appointment status, valid even before the patient has
    // arrived (the top-level 'status' above is only ever set once the
    // appointment is part of today's active queue).
    'appointment_status' => $board['status'] ?? null,
]);
