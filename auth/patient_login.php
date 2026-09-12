<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) redirect(role_dashboard_url(current_role()));
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $errors[] = 'Invalid session token.';
    if (!$errors) {
        $result = authenticate_role(get_db_connection(), trim($_POST['identity'] ?? ''), $_POST['password'] ?? '', ['patient']);
        if ($result['success']) redirect('/medi/patient/dashboard.php');
        $errors[] = $result['message'];
    }
}
$pageTitle = 'Patient Login'; $bodyAccent = 'patient'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card"><div class="mp-form-eyebrow">Patient portal</div><h1>Welcome back</h1><p class="mp-form-intro">Access appointments, tokens, queue progress, and care updates.</p>
<?php foreach ($errors as $error): ?><div class="mp-flash mp-flash-error"><?php echo e($error); ?></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><label for="identity">Patient ID or email</label><input id="identity" name="identity" autocomplete="username" required><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button class="mp-btn mp-btn-primary mp-btn-wide">Sign in</button></form>
<p>New patient? <a href="/medi/auth/register.php">Create your account</a></p><p><a href="/medi/auth/login.php">Back to sign in</a></p></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
