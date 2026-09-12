<?php
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$pdo = get_db_connection();
$specialization_id = $_GET['specialization_id'] ?? '';

if ($specialization_id === '') {
    json_response([]);
}

$stmt = $pdo->prepare("SELECT doctor_id, full_name, status FROM doctors WHERE specialization_id = ? ORDER BY full_name");
$stmt->execute([$specialization_id]);
json_response($stmt->fetchAll());
