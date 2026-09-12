<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/appointment_functions.php';
require_once __DIR__ . '/../includes/consultation_functions.php';

require_doctor_face_verification();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    set_flash('error', 'Invalid request.');
    redirect('/medi/doctor/dashboard.php');
}

$pdo = get_db_connection();
$doctor_id = $_SESSION['doctor_id'];
$appointment_id = (int) ($_POST['appointment_id'] ?? 0);

$diagnosis = $_POST['diagnosis'] ?? '';
$notes = $_POST['notes'] ?? '';
$instructions = $_POST['instructions'] ?? '';
$followUp = $_POST['follow_up'] ?? '';

$medicines = $_POST['medicine'] ?? [];
$dosages = $_POST['dosage'] ?? [];
$frequencies = $_POST['frequency'] ?? [];
$durations = $_POST['duration'] ?? [];
$medInstructions = $_POST['med_instructions'] ?? [];

$prescriptionItems = [];
$count = is_array($medicines) ? count($medicines) : 0;
for ($i = 0; $i < $count; $i++) {
    $prescriptionItems[] = [
        'medicine' => $medicines[$i] ?? '',
        'dosage' => $dosages[$i] ?? '',
        'frequency' => $frequencies[$i] ?? '',
        'duration' => $durations[$i] ?? '',
        'instructions' => $medInstructions[$i] ?? '',
    ];
}

$labTestsRaw = $_POST['lab_test'] ?? [];
$labTests = is_array($labTestsRaw) ? $labTestsRaw : [];

$result = save_consultation_and_complete(
    $pdo, $doctor_id, $appointment_id,
    $diagnosis, $notes, $instructions, $followUp,
    $prescriptionItems, $labTests
);

if ($result['success']) {
    set_flash('success', 'Consultation completed.');
} else {
    set_flash('error', $result['message']);
}

redirect('/medi/doctor/dashboard.php');

