<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/priority_engine.php';
require_once __DIR__ . '/../includes/appointment_functions.php';

require_role('nurse');

$pdo = get_db_connection();
$nurse_id = $_SESSION['nurse_id'];

$nurseStmt = $pdo->prepare("SELECT n.*, d.name AS department_name FROM nurses n LEFT JOIN departments d ON d.department_id = n.department_id WHERE n.nurse_id = ?");
$nurseStmt->execute([$nurse_id]);
$nurse = $nurseStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash('error', 'Invalid session token. Please try again.');
        redirect('/medi/nurse/dashboard.php');
    }

    $action = $_POST['action'] ?? '';
    $appointment_id = (int) ($_POST['appointment_id'] ?? 0);

    if ($action === 'mark_arrival') {
        $result = mark_patient_arrival($pdo, $appointment_id);
        if ($result['success']) {
            set_flash('success', 'Patient marked as arrived successfully.');
        } else {
            set_flash('error', $result['message']);
        }
        redirect('/medi/nurse/dashboard.php');
    }

    if ($action === 'record_vitals') {
        if ($appointment_id <= 0) {
            set_flash('error', 'Please select a patient first.');
            redirect('/medi/nurse/dashboard.php');
        }

        $temperature = trim((string)($_POST['temperature'] ?? ''));
        $bloodPressure = trim((string)($_POST['blood_pressure'] ?? ''));
        $pulse = trim((string)($_POST['pulse'] ?? ''));
        $oxygen = trim((string)($_POST['oxygen_saturation'] ?? ''));
        $weight = trim((string)($_POST['weight'] ?? ''));

        if ($temperature === '' || $bloodPressure === '' || $pulse === '' || $oxygen === '') {
            set_flash('error', 'Temperature, blood pressure, pulse, and oxygen saturation are required.');
            redirect('/medi/nurse/dashboard.php');
        }

        $stmt = $pdo->prepare("INSERT INTO nurse_vitals (appointment_id, nurse_id, temperature, blood_pressure, pulse, oxygen_saturation, weight_kg) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $appointment_id,
            $nurse_id,
            $temperature !== '' ? $temperature : null,
            $bloodPressure !== '' ? $bloodPressure : null,
            $pulse !== '' ? (int)$pulse : null,
            $oxygen !== '' ? (int)$oxygen : null,
            $weight !== '' ? $weight : null,
        ]);

        $pdo->prepare("UPDATE appointments SET preparation_status = 'in_progress', nurse_notes = ? WHERE appointment_id = ?")
            ->execute([
                'Vitals recorded by nurse',
                $appointment_id,
            ]);

        $doctorStmt = $pdo->prepare("SELECT u.user_id, d.full_name FROM appointments a JOIN doctors d ON d.doctor_id = a.doctor_id JOIN users u ON u.user_id = d.user_id WHERE a.appointment_id = ? LIMIT 1");
        $doctorStmt->execute([$appointment_id]);
        $doctorInfo = $doctorStmt->fetch();
        if ($doctorInfo) {
            add_notification($pdo, (int)$doctorInfo['user_id'], 'Nurse recorded vitals for ' . $doctorInfo['full_name'] . '.');
        }

        set_flash('success', 'Vitals recorded successfully.');
        redirect('/medi/nurse/dashboard.php');
    }

    if ($action === 'update_prep_status') {
        $status = $_POST['preparation_status'] ?? 'pending';
        if (!in_array($status, ['pending','in_progress','ready'], true)) {
            $status = 'pending';
        }

        $pdo->prepare("UPDATE appointments SET preparation_status = ? WHERE appointment_id = ?")->execute([$status, $appointment_id]);
        set_flash('success', 'Preparation status updated.');
        redirect('/medi/nurse/dashboard.php');
    }

    if ($action === 'update_emergency_status') {
        $emergencyStatus = $_POST['emergency_status'] ?? 'triggered';
        $allowed = ['triggered','priority_assigned','staff_alerted','acknowledged','awaiting_doctor','doctor_assigned','response_in_progress','in_progress','handled'];
        if (!in_array($emergencyStatus, $allowed, true)) {
            $emergencyStatus = 'acknowledged';
        }

        $pdo->prepare("UPDATE emergency_requests SET status = ? WHERE appointment_id = ? AND status != 'handled'")->execute([$emergencyStatus, $appointment_id]);
        set_flash('success', 'Emergency response status updated.');
        redirect('/medi/nurse/dashboard.php');
    }
}

recalculate_queue($pdo);

$todayPatientsStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = CURDATE() AND status NOT IN ('cancelled')");
$todayPatientsStmt->execute();
$todayPatients = (int) $todayPatientsStmt->fetch()['cnt'];

$arrivedStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = CURDATE() AND arrival_status = 'arrived' AND status NOT IN ('cancelled','completed')");
$arrivedStmt->execute();
$arrivedPatients = (int) $arrivedStmt->fetch()['cnt'];

$waitingStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = CURDATE() AND status IN ('waiting','arrived') AND status NOT IN ('cancelled','completed')");
$waitingStmt->execute();
$waitingPatients = (int) $waitingStmt->fetch()['cnt'];

$emergencyStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM emergency_requests WHERE status != 'handled'");
$emergencyStmt->execute();
$activeEmergencies = (int) $emergencyStmt->fetch()['cnt'];

$appointmentsStmt = $pdo->prepare("
    SELECT a.*, p.full_name AS patient_name, s.name AS specialization_name, d.full_name AS doctor_name,
           q.queue_position, q.priority_score, nv.temperature, nv.blood_pressure, nv.pulse, nv.oxygen_saturation
    FROM appointments a
    JOIN patients p ON p.patient_id = a.patient_id
    JOIN specializations s ON s.specialization_id = a.specialization_id
    LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
    LEFT JOIN queue_entries q ON q.appointment_id = a.appointment_id
    LEFT JOIN nurse_vitals nv ON nv.appointment_id = a.appointment_id
    WHERE a.appointment_date = CURDATE()
    ORDER BY a.appointment_time ASC
");
$appointmentsStmt->execute();
$appointments = $appointmentsStmt->fetchAll();

$alertsStmt = $pdo->prepare("SELECT er.*, p.full_name AS patient_name, a.token FROM emergency_requests er JOIN patients p ON p.patient_id = er.patient_id LEFT JOIN appointments a ON a.appointment_id = er.appointment_id WHERE er.status != 'handled' ORDER BY er.triggered_at DESC");
$alertsStmt->execute();
$alerts = $alertsStmt->fetchAll();

$notifications = get_notifications($pdo, $_SESSION['user_id'], 5);

$pageTitle = 'Nurse Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>Nurse Dashboard</h1>
<p>Department: <?php echo e($nurse['department_name'] ?? 'General'); ?></p>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Today's Patients</h2>
        <p><strong><?php echo (int)$todayPatients; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Arrived Patients</h2>
        <p><strong><?php echo (int)$arrivedPatients; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Waiting Patients</h2>
        <p><strong><?php echo (int)$waitingPatients; ?></strong></p>
    </div>
    <div class="mp-card">
        <h2>Emergency Patients</h2>
        <p><strong><?php echo (int)$activeEmergencies; ?></strong></p>
    </div>
</div>

<div class="mp-card">
    <h2>Live Queue</h2>
    <table class="mp-table">
        <thead>
            <tr>
                <th>Token</th>
                <th>Patient</th>
                <th>Priority</th>
                <th>Specialization</th>
                <th>Doctor</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($appointments as $row): ?>
                <tr>
                    <td><?php echo e($row['token']); ?></td>
                    <td><?php echo e($row['patient_name']); ?></td>
                    <td><?php echo e($row['priority_score'] ?? '0'); ?></td>
                    <td><?php echo e($row['specialization_name']); ?></td>
                    <td><?php echo e($row['doctor_name'] ?? 'Waiting for Specialist'); ?></td>
                    <td><?php echo e(str_replace('_', ' ', $row['status'])); ?></td>
                    <td>
                        <?php if ($row['arrival_status'] === 'not_arrived'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="mark_arrival">
                                <input type="hidden" name="appointment_id" value="<?php echo (int)$row['appointment_id']; ?>">
                                <button type="submit" class="mp-btn mp-btn-small mp-btn-secondary">Mark Arrived</button>
                            </form>
                        <?php elseif ($row['arrival_status'] === 'arrived' || $row['arrival_status'] === 'late'): ?>
                            <span class="mp-badge">Verified</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($appointments)): ?>
                <tr><td colspan="7">No patients scheduled for today.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="mp-grid">
    <div class="mp-card">
        <h2>Patient Preparation Status</h2>
        <table class="mp-table">
            <thead><tr><th>Patient</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
                <?php foreach ($appointments as $row): ?>
                    <tr>
                        <td><?php echo e($row['patient_name']); ?></td>
                        <td><?php echo e(str_replace('_', ' ', $row['preparation_status'] ?? 'pending')); ?></td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update_prep_status">
                                <input type="hidden" name="appointment_id" value="<?php echo (int)$row['appointment_id']; ?>">
                                <select name="preparation_status">
                                    <option value="pending" <?php echo (($row['preparation_status'] ?? 'pending') === 'pending' ? 'selected' : ''); ?>>Pending</option>
                                    <option value="in_progress" <?php echo (($row['preparation_status'] ?? 'pending') === 'in_progress' ? 'selected' : ''); ?>>In Progress</option>
                                    <option value="ready" <?php echo (($row['preparation_status'] ?? 'pending') === 'ready' ? 'selected' : ''); ?>>Ready</option>
                                </select>
                                <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mp-card">
        <h2>Record Basic Vitals</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="record_vitals">
            <label>Patient</label>
            <select name="appointment_id" required>
                <option value="">Select patient</option>
                <?php foreach ($appointments as $row): ?>
                    <option value="<?php echo (int)$row['appointment_id']; ?>"><?php echo e($row['patient_name']); ?> (<?php echo e($row['token']); ?>)</option>
                <?php endforeach; ?>
            </select>
            <label>Temperature (Â°C)</label>
            <input type="number" step="0.1" name="temperature" placeholder="36.8" required>
            <label>Blood Pressure</label>
            <input type="text" name="blood_pressure" placeholder="120/80" required>
            <label>Pulse</label>
            <input type="number" name="pulse" min="1" max="220" required>
            <label>Oxygen Saturation (%)</label>
            <input type="number" name="oxygen_saturation" min="1" max="100" required>
            <label>Weight (kg)</label>
            <input type="number" step="0.1" name="weight" placeholder="65.5">
            <button type="submit" class="mp-btn mp-btn-primary">Save Vitals</button>
        </form>
    </div>
</div>

<div class="mp-card">
    <h2>Emergency Response Status</h2>
    <table class="mp-table">
        <thead><tr><th>Patient</th><th>Status</th><th>Update</th></tr></thead>
        <tbody>
            <?php foreach ($alerts as $alert): ?>
                <tr>
                    <td><?php echo e($alert['patient_name']); ?></td>
                    <td><span class="mp-badge mp-badge-danger"><?php echo e(str_replace('_', ' ', $alert['status'])); ?></span></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="update_emergency_status">
                            <input type="hidden" name="appointment_id" value="<?php echo (int)($alert['appointment_id'] ?? 0); ?>">
                            <select name="emergency_status">
                                <option value="acknowledged" <?php echo ($alert['status'] === 'acknowledged' ? 'selected' : ''); ?>>Acknowledged</option>
                                <option value="awaiting_doctor" <?php echo ($alert['status'] === 'awaiting_doctor' ? 'selected' : ''); ?>>Awaiting Doctor</option>
                                <option value="doctor_assigned" <?php echo ($alert['status'] === 'doctor_assigned' ? 'selected' : ''); ?>>Doctor Assigned</option>
                                <option value="response_in_progress" <?php echo ($alert['status'] === 'response_in_progress' ? 'selected' : ''); ?>>Response In Progress</option>
                                <option value="handled" <?php echo ($alert['status'] === 'handled' ? 'selected' : ''); ?>>Handled</option>
                            </select>
                            <button type="submit" class="mp-btn mp-btn-small mp-btn-primary">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($alerts)): ?>
                <tr><td colspan="3">No active emergencies.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
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

