<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

$pdo = get_db_connection();
$queue = recalculate_queue($pdo);

$result = array_map(function ($row) {
    return [
        'appointment_id' => (int) $row['appointment_id'],
        'queue_position' => (int) $row['queue_position'],
        'token' => $row['token'],
        'patient_name' => $row['patient_name'],
        'has_emergency' => (bool) $row['has_emergency'],
        'booking_emergency_level' => $row['booking_emergency_level'],
        'priority_score' => $row['priority_score'],
        'appointment_time' => $row['appointment_time'],
        'waiting_minutes' => (int) $row['waiting_minutes'],
        'specialization_name' => $row['specialization_name'],
        'doctor_assigned' => (bool) $row['doctor_id'],
        'doctor_name' => $row['doctor_name'],
        'status' => $row['status'],
        'booking_type' => $row['booking_type'],
    ];
}, $queue);

json_response(['queue' => $result]);
