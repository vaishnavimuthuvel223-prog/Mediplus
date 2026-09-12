<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/emergency_functions.php';

require_login();
if (!in_array(current_role(), ['doctor','nurse','admin','management'], true)) {
    json_response(['success' => false, 'message' => 'Access denied.'], 403);
}

$pdo = get_db_connection();
json_response(['emergencies' => get_active_emergencies($pdo)]);
