<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) redirect(role_dashboard_url(current_role()));
$pdo = get_db_connection();
$enrolledDoctors = (int)$pdo->query("SELECT COUNT(*) FROM doctors d JOIN users u ON u.user_id = d.user_id WHERE u.role = 'doctor' AND u.is_active = 1 AND d.face_template IS NOT NULL AND d.face_template <> ''")->fetchColumn();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $errors[] = 'Invalid session token.';
    if (!$errors) {
        $result = authenticate_role($pdo, trim($_POST['identity'] ?? ''), $_POST['password'] ?? '', ['doctor']);
        if ($result['success']) { unset($_SESSION['doctor_face_verified_at']); redirect('/medi/doctor/face_verify.php'); }
        $errors[] = $result['message'];
    }
}
$pageTitle = 'Doctor Login'; $bodyAccent = 'doctor'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card"><div class="mp-form-eyebrow">Clinical access</div><h1>Doctor workspace</h1><p class="mp-form-intro">Use your issued doctor ID and password. Your face is enrolled once and stays tied to your own doctor profile.</p>
<?php foreach ($errors as $error): ?><div class="mp-flash mp-flash-error"><?php echo e($error); ?></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><label for="identity">Doctor ID</label><input id="identity" name="identity" autocomplete="username" required><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button class="mp-btn mp-btn-primary mp-btn-wide">Continue to verification</button></form>
<p>Doctor accounts are issued by administration. Public registration is disabled.</p><p>Enrollment is one-time per doctor ID. Do not enroll again after your face is saved.</p><p><a href="/medi/auth/doctor_face_login.php">Already enrolled? Sign in with face</a></p><p><a href="/medi/auth/login.php">Back to sign in</a></p></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
