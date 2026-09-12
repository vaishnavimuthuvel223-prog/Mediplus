<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';

require_role('admin');

$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect('/medi/admin/dashboard.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_user_state') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? 'doctor';
        if ($user_id > 0) {
            $state = $pdo->prepare("SELECT is_active FROM users WHERE user_id = ?");
            $state->execute([$user_id]);
            $current = (int) $state->fetch()['is_active'];
            $newState = $current ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?")->execute([$newState, $user_id]);
            add_notification($pdo, $user_id, 'Your account status was updated by the hospital admin.');
            set_flash('success', ucfirst($role) . ' account status updated.');
        }
        redirect('/medi/admin/dashboard.php');
    }

    if ($action === 'update_doctor_status') {
        $doctor_id = (int)($_POST['doctor_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        if (in_array($status, ['available','busy','unavailable'], true)) {
            $pdo->prepare("UPDATE doctors SET status = ? WHERE doctor_id = ?")->execute([$status, $doctor_id]);
            set_flash('success', 'Doctor availability updated.');
        }
        redirect('/medi/admin/dashboard.php');
    }
}

recalculate_queue($pdo);

$patientCount = (int) $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();
$doctorCount = (int) $pdo->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
$nurseCount = (int) $pdo->query("SELECT COUNT(*) FROM nurses")->fetchColumn();
$totalAppointments = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$waitingQueue = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('waiting','arrived') AND status NOT IN ('cancelled','completed')")->fetchColumn();
$activeEmergencies = (int) $pdo->query("SELECT COUNT(*) FROM emergency_requests WHERE status != 'handled'")->fetchColumn();
$availableDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'available'")->fetchColumn();
$busyDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'busy'")->fetchColumn();
$unavailableDoctors = (int) $pdo->query("SELECT COUNT(*) FROM doctors WHERE status = 'unavailable'")->fetchColumn();

$patients = $pdo->query("SELECT p.*, u.username, u.is_active FROM patients p JOIN users u ON u.user_id = p.user_id ORDER BY p.full_name")->fetchAll();
$doctors = $pdo->query("SELECT d.doctor_id, d.full_name, d.status, d.specialization_id, s.name AS specialization_name, u.is_active, u.username FROM doctors d JOIN specializations s ON s.specialization_id = d.specialization_id JOIN users u ON u.user_id = d.user_id ORDER BY d.full_name")->fetchAll();
$nurses = $pdo->query("SELECT n.nurse_id, n.full_name, n.department_id, d.name AS department_name, u.is_active, u.username FROM nurses n LEFT JOIN departments d ON d.department_id = n.department_id JOIN users u ON u.user_id = n.user_id ORDER BY n.full_name")->fetchAll();
$specializations = $pdo->query("SELECT s.*, d.name AS department_name FROM specializations s JOIN departments d ON d.department_id = s.department_id ORDER BY s.name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$activeEmergenciesList = $pdo->query("SELECT er.*, p.full_name AS patient_name, a.token FROM emergency_requests er JOIN patients p ON p.patient_id = er.patient_id LEFT JOIN appointments a ON a.appointment_id = er.appointment_id WHERE er.status != 'handled' ORDER BY er.triggered_at DESC")->fetchAll();

$notifications = get_notifications($pdo, $_SESSION['user_id'], 5);

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Admin Dashboard</h1>

<div class="mp-card">
    <h2>Token &amp; Queue Tools</h2>
    <ul class="mp-links">
        <li><a href="/medi/admin/add_walk_in.php">Add a walk-in patient token</a> â€” for patients who arrive without booking online.</li>
        <li><a href="/medi/admin/pharmacy.php">Pharmacy queue</a> â€” move prescriptions through Sent to Pharmacy â†’ Processing â†’ Ready â†’ Dispensed.</li>
        <li><a href="/medi/admin/lab.php">Lab test queue</a> â€” move lab tests through Processing â†’ Result Available.</li>
        <li><a href="/medi/admin/settings.php">Hospital settings</a> â€” hospital address/location, reminder thresholds, consultation timing.</li>
        <li><a href="/medi/queue/live_queue.php">View live hospital queue</a></li>
    </ul>
</div>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Users</h2>
        <p>Patients: <strong><?php echo (int)$patientCount; ?></strong></p>
        <p>Doctors: <strong><?php echo (int)$doctorCount; ?></strong></p>
        <p>Nurses: <strong><?php echo (int)$nurseCount; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Queue</h2>
        <p>Total today: <strong><?php echo (int)$totalAppointments; ?></strong></p>
        <p>Waiting: <strong><?php echo (int)$waitingQueue; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Emergency</h2>
        <p>Active: <strong><?php echo (int)$activeEmergencies; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Doctor Availability</h2>
        <p>Available: <strong><?php echo (int)$availableDoctors; ?></strong></p>
        <p>Busy: <strong><?php echo (int)$busyDoctors; ?></strong></p>
        <p>Unavailable: <strong><?php echo (int)$unavailableDoctors; ?></strong></p>
    </div>
</div>

<div class="mp-grid">
    <div class="mp-card" id="users">
        <h2>Patients</h2>
        <table class="mp-table">
            <thead><tr><th>Name</th><th>Username</th><th>Status</th></tr></thead>
            <tbody>
                <?php foreach ($patients as $patient): ?>
                    <tr>
                        <td><?php echo e($patient['full_name']); ?></td>
                        <td><?php echo e($patient['username']); ?></td>
                        <td><span class="mp-badge"><?php echo $patient['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mp-card">
        <h2>Doctors</h2>
        <table class="mp-table">
            <thead><tr><th>Name</th><th>Specialization</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($doctors as $doctor): ?>
                    <tr>
                        <td><?php echo e($doctor['full_name']); ?></td>
                        <td><?php echo e($doctor['specialization_name']); ?></td>
                        <td><?php echo e($doctor['status']); ?></td>
                        <td>
                            <form method="post" style="display:inline-block;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update_doctor_status">
                                <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor['doctor_id']; ?>">
                                <select name="status">
                                    <option value="available" <?php echo ($doctor['status'] === 'available' ? 'selected' : ''); ?>>Available</option>
                                    <option value="busy" <?php echo ($doctor['status'] === 'busy' ? 'selected' : ''); ?>>Busy</option>
                                    <option value="unavailable" <?php echo ($doctor['status'] === 'unavailable' ? 'selected' : ''); ?>>Unavailable</option>
                                </select>
                                <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mp-card">
    <h2>Nurses</h2>
    <table class="mp-table">
        <thead><tr><th>Name</th><th>Department</th><th>Username</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ($nurses as $nurse): ?>
                <tr>
                    <td><?php echo e($nurse['full_name']); ?></td>
                    <td><?php echo e($nurse['department_name'] ?? 'General'); ?></td>
                    <td><?php echo e($nurse['username']); ?></td>
                    <td><span class="mp-badge"><?php echo $nurse['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

<div class="mp-card">
    <h2>Staff provisioning</h2>
    <p>Doctor, nurse, and administration accounts are issued securely by the system owner. Public staff registration is disabled.</p>
</div>

<div class="mp-card">
    <h2>Active Emergencies</h2>
    <?php if (empty($activeEmergenciesList)): ?>
        <p>No active emergencies.</p>
    <?php else: ?>
        <table class="mp-table">
            <thead><tr><th>Patient</th><th>Status</th><th>Token</th></tr></thead>
            <tbody>
                <?php foreach ($activeEmergenciesList as $em): ?>
                    <tr>
                        <td><?php echo e($em['patient_name']); ?></td>
                        <td><?php echo e(str_replace('_', ' ', $em['status'])); ?></td>
                        <td><?php echo e($em['token'] ?? 'â€”'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="mp-card">
    <h2>Notifications</h2>
    <?php if (empty($notifications)): ?>
        <p>No notifications yet.</p>
    <?php else: ?>
        <ul class="mp-links">
            <?php foreach ($notifications as $note): ?>
                <li><?php echo e($note['message']); ?> <small><?php echo e($note['created_at']); ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

