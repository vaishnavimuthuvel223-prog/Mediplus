<?php
require_once __DIR__ . '/../includes/functions.php';

require_role('patient');

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];

$stmt = $pdo->prepare("SELECT * FROM emergency_requests WHERE patient_id = ? ORDER BY triggered_at DESC LIMIT 1");
$stmt->execute([$patient_id]);
$emergency = $stmt->fetch();

if (!$emergency) {
    json_response(['active' => false]);
}

json_response([
    'active' => $emergency['status'] !== 'handled',
    'emergency_id' => (int) $emergency['emergency_id'],
    'status' => $emergency['status'],
    'triggered_at' => $emergency['triggered_at'],
]);
