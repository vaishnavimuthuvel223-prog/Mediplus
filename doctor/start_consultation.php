<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';

require_doctor_face_verification();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Invalid request.');
    redirect('/medi/doctor/dashboard.php');
}

$pdo = get_db_connection();
$doctor_id = $_SESSION['doctor_id'];
$appointment_id = (int) ($_POST['appointment_id'] ?? 0);

$result = start_consultation($pdo, $doctor_id, $appointment_id);

if ($result['success']) {
    set_flash('success', 'Consultation started.');
} else {
    set_flash('error', $result['message']);
}

redirect('/medi/doctor/dashboard.php');

