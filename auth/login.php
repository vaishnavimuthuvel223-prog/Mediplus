<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    redirect(role_dashboard_url(current_role()));
}

$pdo = get_db_connection();
$enrolledDoctors = (int)$pdo->query("SELECT COUNT(*) FROM doctors d JOIN users u ON u.user_id = d.user_id WHERE u.role = 'doctor' AND u.is_active = 1 AND d.face_template IS NOT NULL AND d.face_template <> ''")->fetchColumn();

$pageTitle = 'Sign in';
$bodyAccent = 'hub';
require_once __DIR__ . '/../includes/header.php';
$token = csrf_token();
?>
<div class="mp-hub-intro">
    <div class="mp-kicker">Secure access</div>
    <h1>Choose your door in.</h1>
    <p>MediPlus keeps every role on its own credentials. Pick your card below and sign in directly - no extra clicks.</p>
</div>

<div class="mp-access-grid">

    <!-- PATIENT -->
    <div class="mp-access-card mp-access-patient">
        <div class="mp-access-top">
            <span class="mp-access-icon">P</span>
            <span class="mp-access-tag">Patient</span>
        </div>
        <h2>Patient portal</h2>
        <p class="mp-access-desc">Track your token, follow the live queue, and manage your appointments.</p>
        <form method="post" action="/medi/auth/patient_login.php" class="mp-access-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($token); ?>">
            <label for="p-identity">Patient ID or email</label>
            <input id="p-identity" name="identity" autocomplete="username" required>
            <label for="p-password">Password</label>
            <input id="p-password" type="password" name="password" autocomplete="current-password" required>
            <button class="mp-btn mp-btn-primary mp-btn-wide">Sign in</button>
        </form>
        <p class="mp-access-foot">New here? <a href="/medi/auth/register.php">Create your account</a></p>
    </div>

    <!-- DOCTOR -->
    <div class="mp-access-card mp-access-doctor">
        <div class="mp-access-top">
            <span class="mp-access-icon">D</span>
            <span class="mp-access-tag">Doctor</span>
        </div>
        <h2>Doctor workspace</h2>
        <p class="mp-access-desc">Sign in, then confirm with your enrolled face before opening consultations.</p>
        <form method="post" action="/medi/auth/doctor_login.php" class="mp-access-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($token); ?>">
            <label for="d-identity">Doctor ID</label>
            <input id="d-identity" name="identity" autocomplete="username" required>
            <label for="d-password">Password</label>
            <input id="d-password" type="password" name="password" autocomplete="current-password" required>
            <button class="mp-btn mp-btn-primary mp-btn-wide">Continue to verification</button>
        </form>
        <p class="mp-access-foot"><?php echo (int)$enrolledDoctors; ?> doctor<?php echo $enrolledDoctors === 1 ? '' : 's'; ?> face-enrolled | <a href="/medi/auth/doctor_face_login.php">sign in with face</a></p>
    </div>

    <!-- ADMIN -->
    <div class="mp-access-card mp-access-admin">
        <div class="mp-access-top">
            <span class="mp-access-icon">A</span>
            <span class="mp-access-tag">Admin</span>
        </div>
        <h2>Administration</h2>
        <p class="mp-access-desc">Manage users, walk-in tokens, hospital settings, and live operations.</p>
        <form method="post" action="/medi/auth/admin_login.php" class="mp-access-form">
            <input type="hidden" name="csrf_token" value="<?php echo e($token); ?>">
            <label for="a-identity">Admin ID</label>
            <input id="a-identity" name="identity" autocomplete="username" required>
            <label for="a-password">Password</label>
            <input id="a-password" type="password" name="password" autocomplete="current-password" required>
            <button class="mp-btn mp-btn-primary mp-btn-wide">Sign in securely</button>
        </form>
        <p class="mp-access-foot">Issued internally | no public registration</p>
    </div>

</div>

<div class="mp-hub-staffnote">
    <span>Nurse or hospital management?</span>
    <a href="/medi/auth/nurse_login.php" class="mp-text-link">Staff sign in <span aria-hidden="true">&#8594;</span></a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

