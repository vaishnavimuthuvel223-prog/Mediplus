<?php
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    if (current_role() === 'patient') redirect('/medi/patient/dashboard.php');
    if (current_role() === 'doctor') redirect('/medi/doctor/dashboard.php');
    if (current_role() === 'nurse') redirect('/medi/nurse/dashboard.php');
    if (current_role() === 'admin') redirect('/medi/admin/dashboard.php');
    if (current_role() === 'management') redirect('/medi/management/dashboard.php');
}

$pageTitle = 'Home';
$bodyAccent = 'home';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mp-hero">
    <div class="mp-hero-copy">
        <div class="mp-kicker">Connected care, clearer decisions</div>
        <h1>Healthcare operations, <em>beautifully in sync.</em></h1>
        <p>One calm workspace for patients, clinicians, nurses, and hospital leaders to move care forward with confidence.</p>
        <div class="mp-hero-actions">
            <a href="/medi/auth/login.php" class="mp-btn mp-btn-primary">Enter MediPlus</a>
            <a href="/medi/queue/live_queue.php" class="mp-text-link">View live queue <span aria-hidden="true">&#8594;</span></a>
        </div>
    </div>
    <div class="mp-hero-panel">
        <div class="mp-panel-topline"><span class="mp-status-dot"></span> Hospital network online</div>
        <div class="mp-panel-metric"><strong>24/7</strong><span>coordinated support</span></div>
        <div class="mp-panel-divider"></div>
        <div class="mp-panel-row"><span>Live queue</span><strong>Active</strong></div>
        <div class="mp-panel-row"><span>Emergency response</span><strong>Ready</strong></div>
    </div>
</div>

<section class="mp-home-section">
    <div class="mp-section-heading">
        <div><div class="mp-kicker">Choose your workspace</div><h2>Everything starts with the right door.</h2></div>
        <p>Purpose-built access keeps every workflow focused, secure, and easy to reach.</p>
    </div>
    <div class="mp-module-grid">
        <a class="mp-module-card" href="/medi/auth/patient_login.php"><span class="mp-module-number">01 | Patient</span><h3>Patient care</h3><p>Book visits, follow your token, and stay connected to your care team.</p><span class="mp-card-arrow">&#8599;</span></a>
        <a class="mp-module-card" href="/medi/auth/doctor_login.php"><span class="mp-module-number">02 | Doctor</span><h3>Doctor workspace</h3><p>Show your enrolled face and open your personal patient-care dashboard instantly.</p><span class="mp-card-arrow">&#8599;</span></a>
        <a class="mp-module-card" href="/medi/auth/admin_login.php"><span class="mp-module-number">03 | Admin</span><h3>Administration</h3><p>Use your issued admin credentials to manage the hospital and operational signals.</p><span class="mp-card-arrow">&#8599;</span></a>
        <a class="mp-module-card" href="/medi/queue/live_queue.php"><span class="mp-module-number">04 | Emergency</span><h3>Live coordination</h3><p>Queue visibility and emergency response connect every authorized team in real time.</p><span class="mp-card-arrow">&#8599;</span></a>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

