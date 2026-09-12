<?php
// =========================================================
// MediPlus - Core Helper Functions
// =========================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_role() {
    return $_SESSION['role'] ?? null;
}

function authenticate_role($pdo, $identity, $password, array $allowedRoles) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$identity, $identity]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid ID or password.'];
    }
    if (!in_array($user['role'], $allowedRoles, true)) {
        return ['success' => false, 'message' => 'This account is not authorized for this login.'];
    }
    if (!(int)$user['is_active']) {
        return ['success' => false, 'message' => 'This account has been deactivated.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $profileId = get_role_profile_id($pdo, (int)$user['user_id'], $user['role']);
    if ($user['role'] === 'patient') $_SESSION['patient_id'] = $profileId;
    if ($user['role'] === 'doctor') $_SESSION['doctor_id'] = $profileId;
    if ($user['role'] === 'admin') $_SESSION['admin_id'] = $profileId;
    if ($user['role'] === 'management') $_SESSION['management_id'] = $profileId;

    return ['success' => true, 'user' => $user];
}

function require_login() {
    if (!is_logged_in()) {
        redirect('/medi/auth/login.php');
    }
}

function require_role($role) {
    require_login();
    if (current_role() !== $role) {
        http_response_code(403);
        die('Access denied. This page is restricted to ' . htmlspecialchars($role) . ' accounts.');
    }
}

function require_doctor_face_verification() {
    require_role('doctor');
    if (empty($_SESSION['doctor_face_verified_at'])) {
        redirect('/medi/doctor/face_verify.php');
    }
}

function require_any_role(array $roles) {
    require_login();
    $roles = array_values($roles);
    if (!in_array(current_role(), $roles, true)) {
        http_response_code(403);
        die('Access denied. This page is restricted to authorized staff accounts.');
    }
}

function role_dashboard_url($role) {
    $routes = [
        'patient' => '/medi/patient/dashboard.php',
        'doctor' => '/medi/doctor/dashboard.php',
        'nurse' => '/medi/nurse/dashboard.php',
        'admin' => '/medi/admin/dashboard.php',
        'management' => '/medi/management/dashboard.php',
    ];
    return $routes[$role] ?? '/medi/index.php';
}

function get_role_profile_id($pdo, $user_id, $role) {
    $roleTableMap = [
        'patient' => 'patients',
        'doctor' => 'doctors',
        'nurse' => 'nurses',
        'admin' => 'admins',
        'management' => 'hospital_management',
    ];

    if (!isset($roleTableMap[$role])) {
        return null;
    }

    $table = $roleTableMap[$role];
    $primaryKey = $role === 'management' ? 'management_id' : substr($table, 0, -1) . '_id';
    if ($table === 'patients') {
        $primaryKey = 'patient_id';
    }
    if ($table === 'doctors') {
        $primaryKey = 'doctor_id';
    }
    if ($table === 'nurses') {
        $primaryKey = 'nurse_id';
    }
    if ($table === 'admins') {
        $primaryKey = 'admin_id';
    }

    $stmt = $pdo->prepare("SELECT {$primaryKey} FROM {$table} WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return $row ? (int) $row[$primaryKey] : null;
}

function add_notification($pdo, $user_id, $message) {
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
    $stmt->execute([$user_id, $message]);
    return (int) $pdo->lastInsertId();
}

function get_notifications($pdo, $user_id, $limit = 10) {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$user_id, (int) $limit]);
    return $stmt->fetchAll();
}

function mark_notifications_read($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
}

function set_flash($type, $message) {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes() {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_setting($key, $default = null) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $value = $row ? $row['setting_value'] : $default;
    $cache[$key] = $value;
    return $value;
}

function calculate_age_category($dob) {
    $birth = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birth)->y;
    if ($age < 12) return 'child';
    if ($age >= 60) return 'senior';
    return 'adult';
}

// Generates a stable sequential token: A-001, A-002 ... for online
// bookings, W-001, W-002 ... for walk-ins (same central queue either
// way - the prefix is only a display convention for how the token
// was created, per the walk-in token example in the spec).
function generate_token($pdo, $booking_type = 'online') {
    $prefix = ($booking_type === 'walk_in') ? 'W' : 'A';
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = CURDATE() AND booking_type = ?');
    $stmt->execute([$booking_type]);
    $row = $stmt->fetch();
    $next = (int)$row['cnt'] + 1;
    return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function minutes_between_now($datetime) {
    if (!$datetime) return 0;
    $then = new DateTime($datetime);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $then->getTimestamp();
    return max(0, (int)round($diff / 60));
}

