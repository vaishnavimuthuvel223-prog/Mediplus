<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Invalid request.');
    redirect('/medi/patient/dashboard.php');
}

$pdo = get_db_connection();
$appointment_id = (int) ($_POST['appointment_id'] ?? 0);

// Ensure this appointment belongs to the logged-in patient
$check = $pdo->prepare("SELECT patient_id FROM appointments WHERE appointment_id = ?");
$check->execute([$appointment_id]);
$owner = $check->fetch();

if (!$owner || (int)$owner['patient_id'] !== (int)$_SESSION['patient_id']) {
    set_flash('error', 'Appointment not found.');
    redirect('/medi/patient/dashboard.php');
}

$result = mark_patient_arrival($pdo, $appointment_id);

if ($result['success']) {
    set_flash('success', !empty($result['late']) ? 'Arrival recorded (marked as late).' : 'Arrival recorded. You are now in the live queue.');
} else {
    set_flash('error', $result['message']);
}

redirect('/medi/patient/dashboard.php');

