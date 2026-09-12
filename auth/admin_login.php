<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) redirect(role_dashboard_url(current_role()));
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) $errors[] = 'Invalid session token.';
    if (!$errors) {
        $result = authenticate_role(get_db_connection(), trim($_POST['identity'] ?? ''), $_POST['password'] ?? '', ['admin', 'management']);
        if ($result['success']) redirect(role_dashboard_url($result['user']['role']));
        $errors[] = $result['message'];
    }
}
$pageTitle = 'Administration Login'; $bodyAccent = 'admin'; require_once __DIR__ . '/../includes/header.php';
?>
<div class="mp-card mp-form-card"><div class="mp-form-eyebrow">Restricted access</div><h1>Administration</h1><p class="mp-form-intro">Hospital administrators use issued credentials to manage people, queues, and response operations.</p>
<?php foreach ($errors as $error): ?><div class="mp-flash mp-flash-error"><?php echo e($error); ?></div><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><label for="identity">Admin ID</label><input id="identity" name="identity" autocomplete="username" required><label for="password">Password</label><input id="password" type="password" name="password" autocomplete="current-password" required><button class="mp-btn mp-btn-primary mp-btn-wide">Sign in securely</button></form>
<p>Administration accounts are provisioned internally. No public registration is available.</p><p><a href="/medi/auth/login.php">Back to sign in</a></p></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
