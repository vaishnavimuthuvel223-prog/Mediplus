<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/emergency_functions.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!verify_csrf($input['csrf_token'] ?? '')) {
    json_response(['success' => false, 'message' => 'Invalid session token.'], 403);
}

$pdo = get_db_connection();
$patient_id = $_SESSION['patient_id'];

if (!$patient_id) {
    json_response(['success' => false, 'message' => 'Patient profile not found.'], 400);
}

$result = trigger_emergency($pdo, $patient_id);
json_response($result);
