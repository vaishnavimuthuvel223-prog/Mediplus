<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Invalid request.');
    redirect('/medi/patient/my_appointments.php');
}

$pdo = get_db_connection();
$appointment_id = (int) ($_POST['appointment_id'] ?? 0);

$result = cancel_appointment($pdo, $appointment_id, $_SESSION['patient_id']);

if ($result['success']) {
    set_flash('success', 'Appointment cancelled.');
} else {
    set_flash('error', $result['message']);
}

redirect('/medi/patient/my_appointments.php');

