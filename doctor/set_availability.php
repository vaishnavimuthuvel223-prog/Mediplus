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
$status = $_POST['status'] ?? '';

if (!in_array($status, ['available','busy','unavailable'])) {
    set_flash('error', 'Invalid status.');
    redirect('/medi/doctor/dashboard.php');
}

$update = $pdo->prepare("UPDATE doctors SET status = ? WHERE doctor_id = ?");
$update->execute([$status, $doctor_id]);

// If doctor just became available, immediately try to assign waiting patients
if ($status === 'available') {
    attempt_doctor_assignment($pdo);
}

// If doctor becomes unavailable, unassign them from any not-yet-started patients
// so those patients fall back to "Waiting for Specialist" and can be picked up
// by another doctor of the same specialization.
if ($status === 'unavailable') {
    $unassign = $pdo->prepare("
        UPDATE appointments SET doctor_id = NULL
        WHERE doctor_id = ? AND status IN ('waiting','arrived')
    ");
    $unassign->execute([$doctor_id]);
    attempt_doctor_assignment($pdo);
}

set_flash('success', 'Availability updated.');
redirect('/medi/doctor/dashboard.php');

