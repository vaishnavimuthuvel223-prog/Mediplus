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

$emergency_id = $input['emergency_id'] ?? null;
$latitude = $input['latitude'] ?? null;
$longitude = $input['longitude'] ?? null;

if (!$emergency_id || $latitude === null || $longitude === null) {
    json_response(['success' => false, 'message' => 'Missing location data.'], 400);
}

// Verify the emergency belongs to this patient before storing consent-based location
$check = $pdo->prepare("SELECT patient_id FROM emergency_requests WHERE emergency_id = ?");
$check->execute([$emergency_id]);
$owner = $check->fetch();

if (!$owner || (int)$owner['patient_id'] !== (int)$patient_id) {
    json_response(['success' => false, 'message' => 'Emergency request not found for this patient.'], 404);
}

$result = store_emergency_location($pdo, $emergency_id, $latitude, $longitude);
json_response($result);
