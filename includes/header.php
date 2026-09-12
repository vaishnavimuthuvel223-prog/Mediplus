<?php require_once __DIR__ . '/functions.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? e($pageTitle) . ' - MediPlus' : 'MediPlus'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/medi/assets/css/style.css">
</head>
<body class="mp-theme-<?php echo e($bodyAccent ?? 'default'); ?>">
<div class="mp-blob-field" aria-hidden="true">
    <span class="mp-blob mp-blob-a"></span>
    <span class="mp-blob mp-blob-b"></span>
    <span class="mp-blob mp-blob-c"></span>
    <span class="mp-blob mp-blob-d"></span>
</div>
<header class="mp-navbar">
    <div class="mp-navbar-inner">
        <a class="mp-brand" href="/medi/index.php"><span class="mp-brand-mark">+</span>Medi<span>Plus</span></a>
        <nav class="mp-nav-links">
            <?php if (is_logged_in()): ?>
                <?php if (current_role() === 'patient'): ?>
                    <a href="/medi/patient/dashboard.php">Dashboard</a>
                    <a href="/medi/patient/book_appointment.php">Book Appointment</a>
                    <a href="/medi/patient/my_appointments.php">My Appointments</a>
                <?php elseif (current_role() === 'doctor'): ?>
                    <a href="/medi/doctor/dashboard.php">Dashboard</a>
                <?php elseif (current_role() === 'nurse'): ?>
                    <a href="/medi/nurse/dashboard.php">Dashboard</a>
                    <a href="/medi/nurse/dashboard.php#patients">Patients</a>
                <?php elseif (current_role() === 'admin'): ?>
                    <a href="/medi/admin/dashboard.php">Dashboard</a>
                    <a href="/medi/admin/dashboard.php#users">Users</a>
                    <a href="/medi/admin/add_walk_in.php">Add Walk-in Token</a>
                    <a href="/medi/admin/pharmacy.php">Pharmacy</a>
                    <a href="/medi/admin/lab.php">Lab</a>
                    <a href="/medi/admin/settings.php">Hospital Settings</a>
                <?php elseif (current_role() === 'management'): ?>
                    <a href="/medi/management/dashboard.php">Dashboard</a>
                    <a href="/medi/management/dashboard.php#overview">Overview</a>
                <?php endif; ?>
                <a href="/medi/queue/live_queue.php">Live Queue</a>
                <?php if (in_array(current_role(), ['doctor','nurse','admin','management'])): ?>
                    <a href="/medi/emergency/emergency_alerts.php">Emergency Alerts</a>
                <?php endif; ?>
                <a class="mp-nav-pill" href="/medi/auth/logout.php">Logout | <?php echo e($_SESSION['username'] ?? ''); ?></a>
            <?php else: ?>
                <a href="/medi/queue/live_queue.php">Live Queue</a>
                <a href="/medi/auth/register.php">Register</a>
                <a class="mp-nav-pill" href="/medi/auth/login.php">Sign in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="mp-container">
<?php foreach (get_flashes() as $flash): ?>
    <div class="mp-flash mp-flash-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
<?php endforeach; ?>

